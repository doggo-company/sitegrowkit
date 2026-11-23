<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 原本から派生物を作る（Phase 9.5-D / ADR-0018・SPEC §4、release-20260909 で幅候補を追加）。
 *
 * ## upload を絶対に失敗させない
 *
 * ここは **best-effort** である。能力が無い・対象外・上限超過・生成中の例外、
 * いずれの場合も **例外を投げずに「作らなかった」を返す**。原本は必ず残っており、
 * 派生物が無くても配信は成立するからである。
 *
 * ## 孤児を残さない
 *
 * 一時パスへ書き、成功したときだけ確定パスへ移す。途中で落ちたら一時パスを消す。
 * DB 行を作るのは呼び出し側で、失敗したら `discard()` で確定パスを消す。
 *
 * ## 同寸の変換と、幅候補
 *
 * target は **形式 + 幅**（`VariantTarget`）。原本と同寸の target は従来どおり format 変換だけ
 * （確定パスも従来のまま `<format>.<format>`）。原本より小さい幅の target は比率を保って縮小する
 * （`<format>-<width>.<format>`）。**拡大は作らない**（`VariantCapability` が拒む）。
 *
 * 原本の decode は **1 回だけ**行い、全 target をそこから作る。decode のたびに
 * 原本を読み直さない（IO と CPU を target の数だけ増やさない）。
 *
 * ## DB に書くのは実寸
 *
 * descriptor の width / height は、書き出した実体を **読み返して**測った値である。
 * 「作ったつもり」の寸法を記録しない。
 */
final class VariantGenerator
{
    public function __construct(private readonly VariantCapability $capability = new VariantCapability) {}

    /**
     * 作れるものだけ作る。
     *
     * `$only` を渡すと、その target だけを試す。**作り直しでは必ず渡すこと** —
     * 既にある target まで作ると、確定パスが同じなので既存の実体を上書きしてしまい、
     * 「余分に作ったから消す」とすれば **DB 行が残ったまま実体だけ消える**。
     * 作らなければ、そのどちらも起きない。
     *
     * `$only` に拡大や対象外の target が混ざっていても作らない（能力の判定は必ずここでも通す）。
     *
     * @param  list<VariantTarget>|null  $only  null = 作れるものすべて
     * @return list<array{format: string, disk: string, path: string, mime: string, size: int, width: int, height: int}>
     */
    public function generate(
        string $disk,
        string $originalPath,
        string $mime,
        int $width,
        int $height,
        ?array $only = null,
    ): array {
        $targets = [];

        foreach ($only ?? $this->capability->targetsFor($mime, $width, $height) as $target) {
            if ($this->capability->allows($mime, $target, $width, $height)) {
                $targets[] = $target;
            }
        }

        if ($targets === []) {
            return [];
        }

        $storage = Storage::disk($disk);

        if (! $this->capability->canDecode($mime) || ! $storage->exists($originalPath)) {
            return [];
        }

        try {
            $image = $this->decode($storage->path($originalPath), $mime);
        } catch (Throwable) {
            return [];
        }

        if ($image === null) {
            return [];
        }

        $descriptors = [];

        try {
            foreach ($targets as $target) {
                $descriptor = $this->makeOne($storage, $disk, $originalPath, $image, $target, $width, $height);

                if ($descriptor !== null) {
                    $descriptors[] = $descriptor;
                }
            }
        } finally {
            imagedestroy($image);
        }

        return $descriptors;
    }

    /**
     * 作った実体を消す（DB 行の作成に失敗したときの補償）。
     *
     * @param  list<array{disk: string, path: string, ...}>  $descriptors
     */
    public function discard(array $descriptors): void
    {
        foreach ($descriptors as $descriptor) {
            Storage::disk($descriptor['disk'])->delete($descriptor['path']);
        }
    }

    /**
     * 原本のパスから、その media の variant を置くディレクトリ。
     *
     * 拡張子置換ではなく **別の名前空間**にするので、`a.png` と `a.webp` を
     * 両方持っても衝突しない（SPEC §3.6）。
     */
    public static function directoryFor(string $originalPath): string
    {
        $dir = trim(pathinfo($originalPath, PATHINFO_DIRNAME), '.'.DIRECTORY_SEPARATOR);
        $stem = pathinfo($originalPath, PATHINFO_FILENAME);

        return ($dir !== '' ? $dir.'/' : '').'variants/'.$stem;
    }

    /**
     * 確定パス。`$width` が null なら同寸の変換（従来のパス — 既存の行が指している）。
     * 幅候補は `<format>-<width>.<format>`。同寸のパスとは**決して一致しない**。
     */
    public static function pathFor(string $originalPath, VariantFormat $format, ?int $width = null): string
    {
        $name = $width === null ? $format->value : $format->value.'-'.$width;

        return self::directoryFor($originalPath).'/'.$name.'.'.$format->value;
    }

    public static function pathForTarget(string $originalPath, VariantTarget $target, int $originalWidth): string
    {
        return self::pathFor($originalPath, $target->format, $target->isFullSize($originalWidth) ? null : $target->width);
    }

    /**
     * @return array{format: string, disk: string, path: string, mime: string, size: int, width: int, height: int}|null
     */
    private function makeOne(
        Filesystem $storage,
        string $disk,
        string $originalPath,
        GdImage $image,
        VariantTarget $target,
        int $width,
        int $height,
    ): ?array {
        $temporary = tempnam(sys_get_temp_dir(), 'agentcms-variant-');

        if ($temporary === false) {
            return null;
        }

        // disk 上の staging パス。**確定パスへ直接書かない** —
        // put が途中で落ちると確定パスに壊れた実体が残り、DB 行が無ければ
        // 誰もそれを消せなくなる。staging なら失敗しても消す先が分かっている
        $staged = self::stagingPathFor($originalPath, $target->format);

        try {
            if (! $this->encode($image, $target, $width, $height, $temporary)) {
                return null;
            }

            // 書き出した実体を読み返して測る。**実寸だけを DB に書く**
            $measured = @getimagesize($temporary);
            $bytes = (int) @filesize($temporary);

            if ($measured === false || $bytes < 1 || (int) $measured[0] < 1 || (int) $measured[1] < 1) {
                return null;
            }

            $handle = @fopen($temporary, 'r');

            if ($handle === false) {
                return null;
            }

            $stored = $storage->put($staged, $handle);

            if (is_resource($handle)) {
                fclose($handle);
            }

            $path = self::pathForTarget($originalPath, $target, $width);

            // move は同じ disk 内の rename。失敗しても staging に残るだけで、
            // 既にある variant も原本も触らない
            if ($stored === false || $storage->move($staged, $path) === false) {
                return null;
            }

            return [
                'format' => $target->format->value,
                'disk' => $disk,
                'path' => $path,
                'mime' => $target->format->mime(),
                'size' => $bytes,
                'width' => (int) $measured[0],
                'height' => (int) $measured[1],
            ];
        } catch (Throwable) {
            // 生成に失敗しても upload は成功のまま。原本で配信する
            return null;
        } finally {
            // 成功しても失敗しても、書きかけを残さない。
            // **消すのは staging と OS の一時ファイルだけ**（確定パスには触れない）
            @unlink($temporary);
            $this->forget($storage, $staged);
        }
    }

    /** staging を消す。move が成功していれば既に無いので、その場合は何も起きない */
    private function forget(Filesystem $storage, string $staged): void
    {
        try {
            if ($storage->exists($staged)) {
                $storage->delete($staged);
            }
        } catch (Throwable) {
            // 掃除に失敗しても呼び出し元へは伝えない。孤児は prune が回収する
        }
    }

    /**
     * 書きかけを置く場所。確定パス（`<format>.<format>` / `<format>-<width>.<format>`）と
     * **決して一致しない**名前にする。
     */
    private static function stagingPathFor(string $originalPath, VariantFormat $format): string
    {
        return self::directoryFor($originalPath)
            .'/.staging-'.Str::uuid()->toString().'.'.$format->value;
    }

    /**
     * 原本を 1 回だけ decode する。**呼ばれる時点で decode 能力があることは確認済み**である。
     *
     * 透過は decode の時点で保つ設定にする。palette PNG は truecolor へ。
     * JPEG に alpha は無いので、この設定は何もしないのと同じである。
     */
    private function decode(string $sourceAbsolute, string $mime): ?GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourceAbsolute),
            'image/png' => @imagecreatefrompng($sourceAbsolute),
            'image/webp' => @imagecreatefromwebp($sourceAbsolute),
            'image/avif' => @imagecreatefromavif($sourceAbsolute),
            default => false,
        };

        if ($image === false) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * target の寸法で書き出す。同寸なら decode したものをそのまま、
     * 幅候補なら **比率を保って**縮小したものを encode する。
     *
     * 縮小先は alpha を保つ truecolor で作る（`imagealphablending(false)` + `imagesavealpha(true)`
     * を **先に**設定してから `imagecopyresampled` する。順序を変えると透過が黒く潰れる）。
     */
    private function encode(GdImage $image, VariantTarget $target, int $width, int $height, string $temporaryPath): bool
    {
        $quality = $this->capability->quality($target->format);

        if ($target->isFullSize($width)) {
            return $this->write($image, $target->format, $temporaryPath, $quality);
        }

        // 幅は target どおり、高さは比率から。1px を下回らせない
        $targetWidth = $target->width;

        if ($targetWidth < 1 || $width < 1 || $height < 1) {
            return false;
        }

        $targetHeight = max(1, (int) round($targetWidth * $height / $width));

        $scaled = imagecreatetruecolor($targetWidth, $targetHeight);

        try {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);

            if (! imagecopyresampled($scaled, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                return false;
            }

            return $this->write($scaled, $target->format, $temporaryPath, $quality);
        } finally {
            imagedestroy($scaled);
        }
    }

    private function write(GdImage $image, VariantFormat $format, string $temporaryPath, int $quality): bool
    {
        return match ($format) {
            VariantFormat::Avif => @imageavif($image, $temporaryPath, $quality),
            VariantFormat::Webp => @imagewebp($image, $temporaryPath, $quality),
        };
    }
}
