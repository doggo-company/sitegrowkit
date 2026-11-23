<?php

declare(strict_types=1);

namespace Modules\Media\Models;

use AgentCms\Core\Content\HasContentSchema;
use AgentCms\Core\Media\ImageProcessor;
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\MediaVisibility;
use AgentCms\Core\Media\VariantFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

final class Media extends Model
{
    use HasContentSchema;

    protected $table = 'media';

    // schema 外のシステムカラム（ユーザー入力させない — ADR-0006）
    protected $fillable = [
        'kind', 'visibility', 'disk', 'original_name',
        'path', 'mime', 'size', 'width', 'height', 'has_webp',
    ];

    protected $casts = [
        'has_webp' => 'boolean',
        'kind' => MediaKind::class,
        'visibility' => MediaVisibility::class,
    ];

    protected $attributes = [
        'kind' => 'image',
        'visibility' => 'public',
        'disk' => 'public',
    ];

    public static function schemaId(): string
    {
        return 'media';
    }

    /** @return HasMany<MediaVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
    }

    /**
     * 保存先 disk。**列を情報源にする。**
     *
     * 列が空なのは、この migration より前に作られた行だけである（既定は public）。
     */
    public function diskName(): string
    {
        return $this->disk !== null && $this->disk !== ''
            ? $this->disk
            : (string) config('agentcms.uploads.disk');
    }

    public function url(): string
    {
        return Storage::disk($this->diskName())->url($this->path);
    }

    /**
     * WebP 変換版の URL（無い場合 null）。
     *
     * `media_variants` を**先に**見て、無ければ旧 `has_webp` を見る（legacy fallback — SPEC §7.1）。
     * migration では backfill しないので、写し替えが済むまで両方が情報源になる。
     */
    public function webpUrl(): ?string
    {
        $variant = $this->largestVariant(VariantFormat::Webp);

        if ($variant !== null) {
            return $variant->url();
        }

        if (! $this->has_webp) {
            return null;
        }

        return Storage::disk($this->diskName())->url(ImageProcessor::webpPath($this->path));
    }

    /**
     * 配信で試す順（AVIF → WebP）に並べた派生物。**DB に行があるものだけ。**
     *
     * 形式ごとに 1 つ — **いちばん大きい幅**（同寸の変換があればそれ）を返す。
     * 管理画面・ピッカーの `variants` はこの形のまま（既存のキーを減らさない）。
     * 幅候補まで含めた配信用の並びは `pictureSources()` が返す。
     *
     * ファイルの実在確認は行わない（配信のたびに IO を増やさない）。
     * 対応していないブラウザは `<img>` の原本へ落ちる。
     *
     * @return list<array{format: string, mime: string, url: string}>
     */
    public function variantSources(): array
    {
        $sources = [];

        foreach (VariantFormat::preferenceOrder() as $format) {
            $variant = $this->largestVariant($format);

            if ($variant !== null) {
                $sources[] = [
                    'format' => $format->value,
                    'mime' => $variant->mime,
                    'url' => $variant->url(),
                ];
            }
        }

        return $sources;
    }

    /**
     * `<picture>` の `<source>` に出す並び（レスポンシブ画像 / release-20260909）。
     *
     * 同じ MIME の行を **1 つの `<source>` にまとめ**、幅の昇順に `url <width>w` を並べた
     * `srcset` にする。候補が 1 つだけなら descriptor を付けない（従来と同じ出力 —
     * 旧実装で登録された同寸だけの画像は、この関数を通っても出力が変わらない）。
     *
     * 幅・URL はすべて **DB の行から導く**。配信のたびにファイルを触らない。
     *
     * @return list<array{format: string, mime: string, srcset: string, responsive: bool}>
     */
    public function pictureSources(): array
    {
        $sources = [];

        foreach (VariantFormat::preferenceOrder() as $format) {
            $rows = $this->variants
                ->where('format', $format->value)
                ->sortBy(static fn (MediaVariant $variant): int => (int) $variant->width)
                ->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $responsive = $rows->count() > 1;

            $sources[] = [
                'format' => $format->value,
                'mime' => $format->mime(),
                'srcset' => $responsive
                    ? $rows->map(static fn (MediaVariant $variant): string => $variant->url().' '.(int) $variant->width.'w')->implode(', ')
                    : $rows->first()->url(),
                'responsive' => $responsive,
            ];
        }

        return $sources;
    }

    /** その形式でいちばん幅の大きい行（同寸の変換があればそれ）。無ければ null */
    private function largestVariant(VariantFormat $format): ?MediaVariant
    {
        $largest = $this->variants
            ->where('format', $format->value)
            ->sortByDesc(static fn (MediaVariant $variant): int => (int) $variant->width)
            ->first();

        return $largest instanceof MediaVariant ? $largest : null;
    }

    /**
     * 管理画面・ピッカーへ渡す表現。
     *
     * **既存のキーは 1 つも減らさず、意味も変えない**（SPEC §7.3）。
     * 足すのは `kind` / `visibility` / `original_name` / `download_url` / `variants` だけ。
     *
     * | キー | image | file |
     * | --- | --- | --- |
     * | `url` | public disk の直 URL | **null** |
     * | `download_url` | null | 配信 route の URL |
     * | `width` / `height` | 数値 | null |
     *
     * `local` disk に `url()` を呼ばない。`local` は URL を持たないので、
     * 呼べば例外か無意味な文字列になる。`file` の到達手段は `download_url` だけである。
     *
     * @return array<string, mixed>
     */
    public function toPickerArray(): array
    {
        $isImage = $this->isImage();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'alt' => $this->alt,
            'url' => $isImage ? $this->url() : null,
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->size,
            'mime' => $this->mime,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'kind' => $this->kind->value,
            'visibility' => $this->visibility->value,
            'original_name' => $this->original_name,
            'download_url' => $isImage ? null : $this->downloadUrl(),
            'variants' => $isImage ? $this->variantSources() : [],
        ];
    }

    /**
     * 一般ファイルへ到達する唯一の URL。
     *
     * `private` は公開の口では 404 になるので、管理画面の口を返す。
     * ファイル名を URL に含めるのは、ブラウザと利用者が保存名を読めるようにするためだけである。
     */
    public function downloadUrl(): string
    {
        if ($this->visibility === MediaVisibility::Private) {
            return route('admin.media.file', ['id' => $this->getKey()]);
        }

        return route('media.file', [
            'id' => $this->getKey(),
            'name' => (string) ($this->original_name ?? $this->name),
        ]);
    }
}
