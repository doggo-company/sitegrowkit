<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

/**
 * この環境で variant を作れるか（Phase 9.5-D / ADR-0018）。
 *
 * ## decode と encode は別物として見る
 *
 * 「`imagewebp` があるか」だけでは足りない。**入力の decode と出力の encode は
 * 別の関数**で、片方だけある環境が実在する（`imageavif` はあるのに
 * `imagecreatefromavif` が無い、など）。組み合わせで判定する。
 *
 * ## メモリは固定式で見積もる
 *
 * 実測に依存させると環境ごとに判定が変わり、テストで固定できない。
 * 保守的な固定式にして、**少なく見積もって落ちるより、多く見積もって諦める**方へ倒す。
 *
 * ```
 * estimated = width * height * 8 + 16MiB
 * ```
 *
 * `* 8` は truecolor の 4 バイト/px を 2 面分（decode した元画像と encode の作業領域）、
 * `+16MiB` は GD とエンコーダの固定オーバーヘッドである。
 */
final class VariantCapability
{
    private const BYTES_PER_PIXEL = 8;

    private const FIXED_OVERHEAD_BYTES = 16 * 1024 * 1024;

    /** decode できる MIME => GD 関数 */
    private const DECODERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
    ];

    /**
     * variant を作らない MIME。
     *
     * SVG はベクタなのでラスタ化しない。GIF は再エンコードすると
     * **アニメーションが失われる**ので原本のみで配信する（owner 決定 D13）。
     *
     * @var list<string>
     */
    private const NEVER_CONVERT = ['image/svg+xml', 'image/gif'];

    /** 幅候補の既定（config の既定と同じ）。config が配列でも文字列でもないときに使う */
    private const DEFAULT_WIDTHS = [640, 1280];

    /** 幅候補として受け付ける範囲。外れた値は**捨てる**（丸めると意図しない幅を作る） */
    private const WIDTH_BOUNDS = [16, 8192];

    /**
     * 幅候補の上限数。**同期生成なので bounded にする**（1 upload あたり
     * 形式 2 × (同寸 1 + 幅候補) の encode が走る）
     */
    private const MAX_WIDTH_CANDIDATES = 6;

    /** config が欠けている / 数でないときに使う値（config の既定と同じ） */
    private const DEFAULTS = [
        'avif_quality' => 50,
        'webp_quality' => 80,
        'max_pixels' => 20_000_000,
        'memory_reserve_bytes' => 32 * 1024 * 1024,
    ];

    /** 設定の許容範囲（外れた値は既定へ戻すのではなく、**安全な側へ丸める**） */
    private const BOUNDS = [
        'avif_quality' => [1, 100],
        'webp_quality' => [1, 100],
        'max_pixels' => [1, 200_000_000],
        // 予約は「少ないほど危ない」ので下限を置く。上限は budget を 0 にするだけなので安全側
        'memory_reserve_bytes' => [8 * 1024 * 1024, 1024 * 1024 * 1024],
    ];

    public function enabled(): bool
    {
        return (bool) config('agentcms.variants.enabled', true);
    }

    public function quality(VariantFormat $format): int
    {
        return $this->setting($format->qualityKey());
    }

    public function maxPixels(): int
    {
        return $this->setting('max_pixels');
    }

    public function memoryReserveBytes(): int
    {
        return $this->setting('memory_reserve_bytes');
    }

    /**
     * 設定値を許容範囲に収めて読む。
     *
     * 生成プロジェクトはこの config を直接編集せず `.env` で上書きする（config 冒頭の約束）。
     * `.env` は文字列なので、**数でない値**（`"high"` など）や
     * **危険な値**（`memory_reserve_bytes=0`、`quality=0`）が入り得る。
     * そこで落とすのではなく、数でなければ既定へ、範囲外なら端へ丸める —
     * 設定の誤りで **upload が失敗するほうが害が大きい**からである。
     */
    private function setting(string $key): int
    {
        [$min, $max] = self::BOUNDS[$key];
        $default = self::DEFAULTS[$key];
        $raw = config('agentcms.variants.'.$key, $default);

        if (! is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

    /**
     * レスポンシブ配信の幅候補（昇順・重複なし）。
     *
     * `.env` は文字列なので `"640,1280"` の形で来る。数でないもの・範囲外のものは
     * **捨てる**（丸めない — 丸めると設定していない幅を作ってしまう）。
     * 空にすれば幅候補を作らない（同寸の format 変換だけになる）。
     *
     * @return list<int>
     */
    public function widths(): array
    {
        $raw = config('agentcms.variants.widths', self::DEFAULT_WIDTHS);

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (! is_array($raw)) {
            return self::DEFAULT_WIDTHS;
        }

        [$min, $max] = self::WIDTH_BOUNDS;
        $widths = [];

        foreach ($raw as $value) {
            $text = trim((string) $value);

            if ($text === '' || ! is_numeric($text)) {
                continue;
            }

            $width = (int) $text;

            if ($width >= $min && $width <= $max) {
                $widths[$width] = $width;
            }
        }

        sort($widths);

        return array_slice($widths, 0, self::MAX_WIDTH_CANDIDATES);
    }

    /**
     * この原本から、この形式の **幅候補**（縮小版）を作ってよいか。
     *
     * 同寸の変換（`supports()`）と違い、**同じ形式でも作る** — WebP の原本から
     * 640px の WebP を作るのは意味がある（スマホ幅で 2400px を配らないため）。
     * **拡大はしない**: 原本以上の幅は false。原本と同じ幅は同寸変換の役目である。
     */
    public function supportsWidth(string $mime, VariantFormat $format, int $width, int $height, int $targetWidth): bool
    {
        if (! $this->enabled() || in_array($mime, self::NEVER_CONVERT, true)) {
            return false;
        }

        if ($targetWidth < 1 || $targetWidth >= $width) {
            return false;
        }

        return $this->canDecode($mime)
            && $this->canEncode($format)
            && $this->withinPixelLimit($width, $height)
            && $this->withinMemoryBudget($width, $height);
    }

    /**
     * この原本に対して、この環境で作り得る target の全部（配信優先順 → 同寸 → 幅の昇順）。
     *
     * 作り直しはここから「まだ行が無いもの」を引く。upload はこれを全部試す。
     *
     * @return list<VariantTarget>
     */
    public function targetsFor(string $mime, int $width, int $height): array
    {
        $targets = [];

        foreach (VariantFormat::preferenceOrder() as $format) {
            if ($this->supports($mime, $format, $width, $height)) {
                $targets[] = new VariantTarget($format, $width);
            }

            foreach ($this->widths() as $candidate) {
                if ($this->supportsWidth($mime, $format, $width, $height, $candidate)) {
                    $targets[] = new VariantTarget($format, $candidate);
                }
            }
        }

        return $targets;
    }

    /** target が同寸なら `supports()`、幅候補なら `supportsWidth()` で判定する */
    public function allows(string $mime, VariantTarget $target, int $width, int $height): bool
    {
        return $target->isFullSize($width)
            ? $this->supports($mime, $target->format, $width, $height)
            : $this->supportsWidth($mime, $target->format, $width, $height, $target->width);
    }

    /** その MIME を decode できるか */
    public function canDecode(string $mime): bool
    {
        $decoder = self::DECODERS[$mime] ?? null;

        return $decoder !== null && function_exists($decoder);
    }

    /** その形式を書き出せるか */
    public function canEncode(VariantFormat $format): bool
    {
        return function_exists($format->encoder());
    }

    public function decoderFor(string $mime): ?string
    {
        return self::DECODERS[$mime] ?? null;
    }

    /**
     * この原本から、この形式を作ってよいか。
     *
     * ここが false なら **variant を作らないだけ**で、upload は成功する。
     */
    public function supports(string $mime, VariantFormat $format, int $width, int $height): bool
    {
        if (! $this->enabled() || in_array($mime, self::NEVER_CONVERT, true)) {
            return false;
        }

        // 同じ形式へ変換しない（webp の原本から webp を作らない）
        if ($mime === $format->mime()) {
            return false;
        }

        return $this->canDecode($mime)
            && $this->canEncode($format)
            && $this->withinPixelLimit($width, $height)
            && $this->withinMemoryBudget($width, $height);
    }

    public function withinPixelLimit(int $width, int $height): bool
    {
        return $width > 0 && $height > 0 && $width * $height <= $this->maxPixels();
    }

    /** 見積りが budget に収まるか。**decode の前**に判断する */
    public function withinMemoryBudget(int $width, int $height): bool
    {
        $budget = $this->memoryBudget();

        // memory_limit = -1（無制限）のときは画素数の上限だけで判断する
        return $budget === null || $this->estimatedBytes($width, $height) <= $budget;
    }

    public function estimatedBytes(int $width, int $height): int
    {
        return $width * $height * self::BYTES_PER_PIXEL + self::FIXED_OVERHEAD_BYTES;
    }

    /** 使ってよいメモリ。無制限なら null */
    public function memoryBudget(): ?int
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - memory_get_usage(true) - $this->memoryReserveBytes());
    }

    /** php.ini の memory_limit をバイトで。無制限なら null */
    public function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
