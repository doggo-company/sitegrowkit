<?php

declare(strict_types=1);

namespace App\Support\SiteTemplates;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Media\Application\UploadMedia;
use RuntimeException;

/** Creates one draft; never publishes, rebuilds edits, or changes the front page. */
final class StarterSite
{
    public const PAGE_SETTING = 'starter_a_page_id';

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly CreatePage $create,
        private readonly PatchPageDocument $patch,
        private readonly UploadMedia $upload,
    ) {}

    public function page(): ?Page
    {
        $id = $this->settings->get(self::PAGE_SETTING);

        return is_numeric($id) ? Page::query()->whereKey((int) $id)->whereNull('trashed_at')->first() : null;
    }

    public function create(OperationContext $context): Page
    {
        if (($page = $this->page()) !== null) {
            return $page;
        }

        return DB::transaction(function () use ($context): Page {
            $slug = Page::query()->where('slug', 'starter-a')->exists() ? 'starter-a-'.Str::lower(Str::random(6)) : 'starter-a';
            $result = $this->must($this->create->execute(['title' => '麦と日々 — トップページの見本', 'slug' => $slug], $context));
            $page = Page::query()->findOrFail((int) ($result->data['page']['id'] ?? 0));
            $media = ['hero' => $this->photo('hero', $context), 1 => $this->photo('1', $context), 2 => $this->photo('2', $context)];
            $root = $page->draftVersion?->document['root']['id'] ?? '';

            foreach (StarterDocument::sections($media) as $section) {
                $this->must($this->patch->execute($page->id, ['operation' => 'component.insert', 'parent' => $root, 'component' => $section], $context));
            }

            $this->settings->put([self::PAGE_SETTING => $page->id]);

            return $page->fresh() ?? $page;
        });
    }

    private function photo(string $slot, OperationContext $context): int
    {
        $path = resource_path("starter-photos/food-{$slot}.jpg");
        $copy = tempnam(sys_get_temp_dir(), 'sgk-starter-');
        if ($copy === false || ! copy($path, $copy)) {
            throw new RuntimeException('見本写真を読み込めませんでした');
        }

        try {
            $file = new UploadedFile($copy, basename($path), 'image/jpeg', null, true);
            $result = $this->must($this->upload->execute($file, $context, 'パン屋の見本写真', '生成した見本写真。ご自身の写真に差し替えてください。'));

            return (int) ($result->data['media']['id'] ?? 0);
        } finally {
            if (is_file($copy)) {
                unlink($copy);
            }
        }
    }

    private function must(Result $result): Result
    {
        if (! $result->isSuccess()) {
            throw new RuntimeException('見本ページを作成できませんでした');
        }

        return $result;
    }
}
