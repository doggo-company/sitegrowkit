<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Application\Content\ContentTaxonomyReader;
use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Wxr\WxrReader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Media\Models\Media;
use RuntimeException;
use Throwable;
use XMLWriter;

/** 記事とページをWXR 1.2へ書き出す。完成前のファイルは配らない。 */
final class ContentExport
{
    public function __construct(
        private readonly ContentTypeResolver $types,
        private readonly ContentTaxonomyReader $taxonomies,
        private readonly DocumentRenderer $renderer,
        private readonly SettingsStore $settings,
        private readonly Purifier $purifier,
    ) {}

    public static function root(): string
    {
        return config('agentcms.content_export_root', storage_path('app/private/content-exports'));
    }

    public static function path(string $id): string
    {
        if (! preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/D', $id)) {
            throw new RuntimeException('書き出しIDが不正です');
        }

        return self::root().'/'.$id.'.xml';
    }

    /** @param callable(int): void $progress */
    public function write(string $id, callable $progress): int
    {
        $path = self::path($id);
        if (! is_dir(self::root()) && ! mkdir(self::root(), 0700, true) && ! is_dir(self::root())) {
            throw new RuntimeException('書き出し先を作成できません');
        }
        $tmp = $path.'.partial';
        // 排他的に作り、既存の完成品・同じ実行の一時ファイルを上書きしない。
        if (is_file($path)) {
            throw new RuntimeException('この書き出しは既に完了しています');
        }
        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            throw new RuntimeException('書き出しの一時ファイルを作成できません');
        }
        chmod($tmp, 0600);
        fclose($handle);
        $xml = new XMLWriter;
        $count = 0;
        $identities = [];
        try {
            if (! $xml->openUri($tmp)) {
                throw new RuntimeException('書き出しファイルを開けません');
            }
            $xml->startDocument('1.0', 'UTF-8');
            $xml->startElement('rss');
            $xml->writeAttribute('version', '2.0');
            $xml->writeAttribute('xmlns:wp', 'http://wordpress.org/export/1.2/');
            $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
            $xml->writeAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
            $xml->writeAttribute('xmlns:excerpt', 'http://wordpress.org/export/1.2/excerpt/');
            $xml->startElement('channel');
            $this->text($xml, 'title', (string) ($this->settings->all()['site_name'] ?? config('app.name')));
            $this->text($xml, 'link', url('/'));
            $this->text($xml, 'wp:wxr_version', '1.2');
            $this->text($xml, 'wp:base_site_url', url('/'));
            $this->text($xml, 'wp:base_blog_url', url('/'));
            // 個人情報を持ち出さず、移行先で投稿者を割り当てるための共通名。
            $xml->startElement('wp:author');
            $this->text($xml, 'wp:author_id', '1');
            $this->text($xml, 'wp:author_login', 'sitegrowkit-export');
            $this->text($xml, 'wp:author_display_name', 'SiteGrowKitからの書き出し');
            $xml->endElement();
            // 同一接続の読み取りを一つの取引にまとめ、全行をメモリへ載せない。
            DB::transaction(function () use ($xml, $progress, &$count, &$identities): void {
                $types = $this->types->availableTypes();
                sort($types);
                foreach ($types as $type) {
                    $schema = $this->types->schema($type);
                    if (! EditorialCollections::isEditorial($schema)) {
                        continue;
                    }
                    foreach ($this->types->newModel($type)->newQuery()->lazyById(100) as $model) {
                        $this->entry($xml, $schema, $model, $identities);
                        $progress(++$count);
                    }
                }
                foreach (Page::query()->where('managed_by', Page::MANAGED_BY_DOCUMENT)
                    ->where('status', '!=', PageStatus::Trashed->value)->with(['draftVersion', 'publishedVersion'])->lazyById(50) as $page) {
                    $version = $page->status === PageStatus::Published ? $page->publishedVersion : $page->draftVersion;
                    if ($version === null) {
                        throw new RuntimeException('本文の版が見つからないページがあります。修復してから書き出してください');
                    }
                    $this->startItem($xml, 'page', $page->id, $page->title, $page->slug,
                        $page->status === PageStatus::Published ? ($page->isScheduled() ? 'future' : 'publish') : 'draft',
                        $page->published_at ?? $page->created_at, $identities);
                    // 見た目用の枠やフォームの動作はWXRへ移さない。本文は許可済みHTMLに絞る。
                    $body = $this->renderer->render($version->document, new RenderContext($page, $this->settings->all(), export: true))->toHtml();
                    $this->text($xml, 'content:encoded', $this->purifier->clean($body));
                    $this->meta($xml, 'sitegrowkit_document_json', $version->document);
                    $this->meta($xml, 'sitegrowkit_seo_json', $page->seo);
                    $xml->endElement();
                    $progress(++$count);
                }
            });
            $xml->endElement();
            $xml->endElement();
            $xml->endDocument();
            $xml->flush();
            unset($xml);
            // ディスク不足などで終端が欠けたXMLを「完了」にしない。読み戻して件数まで確認する。
            $verified = 0;
            foreach ((new WxrReader($tmp))->items() as $item) {
                $verified++;
            }
            if ($verified !== $count) {
                throw new RuntimeException('書き出した件数が一致しません');
            }
            if (! @rename($tmp, $path)) {
                throw new RuntimeException('書き出しファイルを確定できません');
            }

            return $count;
        } catch (Throwable $e) {
            unset($xml);
            @unlink($tmp);
            throw $e;
        }
    }

    /** @param array<string, string> $identities */
    private function entry(XMLWriter $xml, ContentSchema $schema, Model $model, array &$identities): void
    {
        $values = [];
        $data = $model->getAttribute('data');
        foreach ($schema->fields as $field) {
            $values[$field->key] = $field->isColumnBacked() ? $model->getAttribute($field->key) : (is_array($data) ? ($data[$field->key] ?? null) : null);
        }
        $date = $model->getAttribute('published_at');
        $exportDate = $date ?? $model->getAttribute('created_at');
        $status = $model->getAttribute('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;
        $wpStatus = $status === 'published' ? ($date instanceof DateTimeInterface && $date > now() ? 'future' : 'publish') : 'draft';
        $this->startItem($xml, $schema->id, $model->getKey(), (string) ($values[$schema->titleKey() ?? 'title'] ?? ''),
            (string) ($values['slug'] ?? ''), $wpStatus, $exportDate instanceof DateTimeInterface ? $exportDate : null, $identities);
        $this->text($xml, 'content:encoded', $this->purifier->clean((string) ($values['body'] ?? '')));
        $this->text($xml, 'excerpt:encoded', (string) ($values['excerpt'] ?? ''));
        foreach ($this->taxonomies->forEntry($schema, $schema->id, (int) $model->getKey()) as $key => $taxonomy) {
            foreach ($taxonomy['terms'] as $term) {
                $xml->startElement('category');
                $xml->writeAttribute('domain', $key);
                $xml->writeAttribute('nicename', $term['slug']);
                $xml->text($term['name']);
                $xml->endElement();
            }
        }
        // 任意DB列ではなく、編集用スキーマで宣言された値だけを書き出す。
        $this->meta($xml, 'sitegrowkit_fields_json', $values);
        $this->meta($xml, 'sitegrowkit_content_type', $schema->id);
        // CMS内部のメディアIDだけでは移行先が画像を探せないため公開URLも添える。
        $mediaUrls = [];
        foreach ($schema->fields as $field) {
            if ($field->type === 'media' && is_numeric($values[$field->key] ?? null)) {
                $media = Media::query()->where('visibility', 'public')->find((int) $values[$field->key]);
                if ($media !== null) {
                    $mediaUrls[$field->key] = url($media->url());
                }
            }
        }
        $this->meta($xml, 'sitegrowkit_media_urls_json', $mediaUrls);
        $xml->endElement();
    }

    /** @param array<string, string> $identities */
    private function startItem(XMLWriter $xml, string $type, int|string $id, string $title, string $slug,
        string $status, ?DateTimeInterface $date, array &$identities): void
    {
        $source = $type.':'.$id;
        // 型ごとに同じDB IDがあり得る。削除や並び順が変わっても同じ外部IDにする。
        // WordPressはこのIDを実DBにも使う。JSの整数精度を越えない52bitに収める。
        $external = (string) max(1, (int) hexdec(substr(hash('sha256', $source), 0, 13)));
        $key = 'export-'.$external;
        if (isset($identities[$key]) && $identities[$key] !== $source) {
            throw new RuntimeException('書き出しIDが衝突しました。ファイルは作成していません');
        }
        $identities[$key] = $source;
        $xml->startElement('item');
        $this->text($xml, 'title', $title);
        $this->text($xml, 'wp:post_id', $external);
        $this->text($xml, 'dc:creator', 'sitegrowkit-export');
        // WordPress標準インポーターが未知の投稿タイプを捨てないよう、投稿へ対応付ける。
        $this->text($xml, 'wp:post_type', $type === 'page' ? 'page' : 'post');
        $this->text($xml, 'wp:post_name', $slug);
        $this->text($xml, 'wp:status', $status);
        $this->text($xml, 'wp:post_parent', '0');
        $this->text($xml, 'wp:comment_status', 'closed');
        $gmt = $date === null ? '0000-00-00 00:00:00' : \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // インポーターはローカル日時も重複判定に使う。双方をUTCで明示する。
        $this->text($xml, 'wp:post_date', $gmt);
        $this->text($xml, 'wp:post_date_gmt', $gmt);
    }

    private function meta(XMLWriter $xml, string $key, mixed $value): void
    {
        $xml->startElement('wp:postmeta');
        $this->text($xml, 'wp:meta_key', $key);
        $this->text($xml, 'wp:meta_value', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $xml->endElement();
    }

    private function text(XMLWriter $xml, string $name, string $value): void
    {
        // XML 1.0で表せない制御文字を黙って失わない。
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new RuntimeException('XMLに書き出せない文字があります。記事を確認してください');
        }
        if (! $xml->writeElement($name, $value)) {
            throw new RuntimeException('書き出しに失敗しました。空き容量を確認してください');
        }
    }
}
