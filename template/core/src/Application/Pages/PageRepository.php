<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Result;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Page / PageVersion の読み書き（Phase 9.1D）。
 *
 * 継承のための基底クラスではなく composition の部品（owner 決定 G16）。
 * **draft version を作るのはここだけ**にして、版の作り方が経路ごとに割れないようにする。
 */
final class PageRepository
{
    /** 指しているのに版が居ない、という状態の印 */
    private const DRAFT_MISSING = 'missing';

    public function find(int $id): Page|Result
    {
        $page = Page::query()->find($id);

        return $page ?? Result::notFound("ページ（ID: {$id}）は見つかりません");
    }

    /**
     * **書き込むための draft。** 指している版が居なければ断る。
     *
     * ## 2 つの状況を分ける
     *
     * - **下書きがまだ無い**（`draft_version_id` が null）… 正常。公開版から作る
     * - **指しているのに、その版が居ない** … **壊れている**。編集していた内容は
     *   既に消えており、ここで作り直すと**別物の上に書き足させる**ことになる
     *
     * かつては両方とも黙って作り直していた。`pages.draft_version_id` は外部キーでは
     * ないので、指している版を消しても DB は止めない（間引きの側にしか守りが無かった）。
     */
    public function draft(Page $page): PageVersion|Result
    {
        $existing = $this->existingDraft($page);

        if ($existing instanceof PageVersion) {
            return $existing;
        }

        if ($existing === self::DRAFT_MISSING) {
            return Result::conflict('編集中の版が見つかりません。版の履歴から復元してください。');
        }

        return $this->recover($page);
    }

    /**
     * **読むための draft。** 居なければ作り直し、失われたことを**知らせる文面**を返す。
     *
     * 読めなくすると、**版の履歴へ行く道まで塞がる**（履歴の UI は編集画面の中にある）。
     * だから読みは通し、黙って通さない。
     *
     * ## 順序で守りが変わる（**ここが分かりにくいので書いておく**）
     *
     * **読みが復旧させた瞬間、壊れていた状態は正常な状態に変わる。** 以降の書き込みは
     * 普通に通る。つまり `draft()` の拒否が効くのは**読みより先に書きが来た場合**
     * （API / MCP のような headless の経路）だけで、画面から入る経路では
     * **この知らせが守りの本体**である。
     *
     * @return array{0: PageVersion, 1: string|null} 版と、失われたことの知らせ
     */
    public function draftForReading(Page $page): array
    {
        $existing = $this->existingDraft($page);

        if ($existing instanceof PageVersion) {
            return [$existing, null];
        }

        $notice = $existing === self::DRAFT_MISSING
            ? '編集中だった内容は失われました。版の履歴から復元できます。'
            : null;

        return [$this->recover($page), $notice];
    }

    /**
     * いまの draft。**無い理由まで区別して**返す。
     *
     * - `PageVersion` … 在る
     * - `self::DRAFT_MISSING` … 指しているのに居ない（壊れている）
     * - `null` … そもそも指していない（正常）
     */
    private function existingDraft(Page $page): PageVersion|string|null
    {
        if ($page->draft_version_id === null) {
            return null;
        }

        $draft = PageVersion::query()->find($page->draft_version_id);

        if ($draft instanceof PageVersion) {
            return $draft;
        }

        /*
         * **運用の記録にも残す。** 応答は利用者に伝えるが、
         * 「いつ・どのページの・どの版が消えたか」は後から追えないと直せない。
         */
        Log::warning('編集中の版が見つかりません', [
            'page_id' => $page->id,
            'missing_version_id' => $page->draft_version_id,
        ]);

        return self::DRAFT_MISSING;
    }

    /** 公開版から複製して draft を作る（無ければ空） */
    private function recover(Page $page): PageVersion
    {
        $source = $page->published_version_id !== null
            ? PageVersion::query()->find($page->published_version_id)
            : null;

        return $this->createVersion(
            $page,
            $source instanceof PageVersion ? $source->document : DocumentTree::empty(),
            'admin',
            null,
        );
    }

    /**
     * 新しい版を作り、draft として設定する。
     *
     * @param  array<string, mixed>  $document
     */
    public function createVersion(
        Page $page,
        array $document,
        string $interface,
        ?int $userId,
        bool $autosave = false,
    ): PageVersion {
        return DB::transaction(function () use ($page, $document, $interface, $userId, $autosave): PageVersion {
            $revision = (int) PageVersion::query()->where('page_id', $page->id)->max('revision') + 1;

            $version = PageVersion::query()->create([
                'page_id' => $page->id,
                'revision' => $revision,
                'schema_version' => DocumentTree::SCHEMA_VERSION,
                'document' => $document,
                'created_interface' => $interface,
                'created_by' => $userId,
                /*
                 * **既定は「自動保存ではない」**（§4.11）。
                 * 間引かれる側へ入るのは、そう名乗った呼び出しだけである。
                 * 呼び出しを増やした人が黙って間引かれる側へ落ちない。
                 */
                'autosave' => $autosave,
            ]);

            $page->forceFill(['draft_version_id' => $version->id])->save();

            return $version;
        });
    }

    /** 楽観ロック。期待した revision でなければ conflict を返す */
    public function checkRevision(PageVersion $draft, ?int $expected): ?Result
    {
        if ($expected === null || $expected === $draft->revision) {
            return null;
        }

        return Result::conflict(
            "このページは他の編集で更新されています（現在のバージョン: {$draft->revision}）",
            ['currentVersion' => $draft->revision, 'expectedVersion' => $expected],
        );
    }
}
