<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Application\Backups\ListBackups;
use AgentCms\Core\Application\Backups\VerifyBackup;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\DuplicateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\GetContentSchema;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\ListContentTypes;
use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Content\ReorderContent;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\Forms\GetForm;
use AgentCms\Core\Application\Forms\ListForms;
use AgentCms\Core\Application\Menus\GetMenu;
use AgentCms\Core\Application\Menus\ListMenus;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\DuplicatePage;
use AgentCms\Core\Application\Pages\GetPage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\ListPages;
use AgentCms\Core\Application\Pages\ListPageVersions;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Pages\TrashPage;
use AgentCms\Core\Application\Pages\UnpublishPage;
use AgentCms\Core\Application\Redirects\ListRedirects;
use AgentCms\Core\Application\Redirects\SimulateRedirect;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Settings\GetSettings;
use AgentCms\Core\Application\Settings\UpdateSettings;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\DeleteTerm;
use AgentCms\Core\Application\Taxonomy\GetTaxonomy;
use AgentCms\Core\Application\Taxonomy\ListTaxonomies;
use AgentCms\Core\Application\Taxonomy\ListTerms;
use AgentCms\Core\Application\Taxonomy\ReorderTerms;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Application\Taxonomy\UpdateTerm;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\UploadedFile;
use Modules\Media\Application\GetMedia;
use Modules\Media\Application\ListMedia;
use Modules\Media\Application\ReplaceMedia;
use Modules\Media\Application\UpdateMedia;

/**
 * AI エージェント（MCP）へ公開する操作の **allowlist**（Phase 9.1B / STEP 11・14）。
 *
 * ここに無い操作は MCP から実行できない。
 * 任意の Artisan コマンド・shell・SQL・ファイル読み取りは**公開しない**。
 * 削除系は content.delete のみ（media.delete / contact の状態変更は非公開 — owner 指示）。
 */
final class OperationCatalog
{
    /**
     * operation id => 分類。**この配列が公開範囲の唯一の定義**。
     *
     * @var array<string, OperationClassification>
     */
    private const OPERATIONS = [
        'content.types.list' => OperationClassification::Read,
        'content.schema.get' => OperationClassification::Read,
        'content.list' => OperationClassification::Read,
        'content.get' => OperationClassification::Read,
        'content.create' => OperationClassification::Write,
        'content.duplicate' => OperationClassification::Write,
        'content.update' => OperationClassification::Write,
        'content.delete' => OperationClassification::Destructive,
        // 掲載順（P1 ②）。**戻せる**ので Write（`term.reorder` と揃える）
        'content.reorder' => OperationClassification::Write,
        'content.trash' => OperationClassification::Destructive,
        'media.list' => OperationClassification::Read,
        'media.get' => OperationClassification::Read,
        'media.update' => OperationClassification::Write,
        'media.replace' => OperationClassification::Write,
        'settings.get' => OperationClassification::Read,
        'settings.update' => OperationClassification::Write,
        /*
         * **旧お問い合わせの受信（`contact.list` / `contact.get`）はここに載せない**（B-43）。
         *
         * 下の 9.12 の項が「受信（`form.submission.*`）は出さない。本文と連絡先そのもの」
         * と決めているのに、9.12 より前に作られた旧側だけが載ったままだった。
         * **同じ種類のデータについて新旧で決定が逆向き**になっていたので、旧側を 9.12 に揃える。
         *
         * 出ていたのは `ContactSubmission::toArray()` の素通し 14 列で、
         * **送信者の生 IP（`ip`）と本文（`message`）と連絡先を含んでいた**。
         * 問い合わせ主は AI に読ませることに同意していない。
         *
         * **運用者が読む口は塞いでいない** — 管理画面（`/admin/contact`）はこの catalog を
         * 通らず、`ListContacts::paginate()` / `GetContact::find()` を直接使う。
         * `contact.updateStatus` を出していないのと同じ形である（管理画面のみの操作）。
         */
        // Pages（Phase 9.1D）。物理削除（purge）は AI へ公開しない — owner 決定 2
        'page.list' => OperationClassification::Read,
        // 作成は常に下書き。公開は page.publish（EXTERNAL_EFFECT）でしか行えない
        'page.create' => OperationClassification::Write,
        'page.duplicate' => OperationClassification::Write,
        'page.get' => OperationClassification::Read,
        'page.document.get' => OperationClassification::Read,
        'page.document.versions' => OperationClassification::Read,
        'page.document.patch' => OperationClassification::Write,
        'page.publish' => OperationClassification::ExternalEffect,
        'page.unpublish' => OperationClassification::ExternalEffect,
        'page.trash' => OperationClassification::Destructive,
        // 分類（Phase 9.3B / §P.3）。**taxonomy 自体の作成・更新・削除は公開しない**
        // （ContentSchema の taxonomies[] 宣言と対でしか意味を持たないため Phase 9.4 へ延期 — §P.2）。
        // 公開するのは **taxonomy の read と term の操作**だけ
        'taxonomy.list' => OperationClassification::Read,
        'taxonomy.get' => OperationClassification::Read,
        'term.list' => OperationClassification::Read,
        'term.create' => OperationClassification::Write,
        // archive / unarchive は term.update へ統合する（操作を増やさない — §I.5）
        'term.update' => OperationClassification::Write,
        'term.reorder' => OperationClassification::Write,
        'term.delete' => OperationClassification::Destructive,

        /*
         * メニュー（Phase 9.9 / P0-4・SPEC §E.3）。**参照だけ**。
         *
         * `menu.save` / `menu.delete` は出さない。サイトの導線は構造であって、
         * AI が黙って書き換えてよいものではない（9.8 の OG3 と同じ判断）。
         * 「メニューに追加して」と頼まれた AI は、管理画面の場所を答えればよい。
         */
        'menu.list' => OperationClassification::Read,
        'menu.get' => OperationClassification::Read,

        /*
         * リダイレクト（Phase 9.10 / P0-5・SPEC §E.2）。**参照だけ**。
         *
         * `redirect.save` / `redirect.delete` は出さない。サイトの入口を
         * AI が黙って書き換えてよい理由が無い。
         * `redirect.simulate` を出すのは、「この URL はどこへ飛ぶか」に答えられると
         * 移行の相談に役立ち、かつ **何も変えない**からである。
         *
         * **404 の記録は出さない** — referrer と user-agent は個人に近づく。
         */
        'redirect.list' => OperationClassification::Read,
        'redirect.simulate' => OperationClassification::Read,

        /*
         * backup（Phase 9.11 / P0-6・SPEC §E.2）。**参照だけ**。
         *
         * `backup.restore` / `backup.delete` / `backup.download` は出さない。
         * 復元はサイト全体を過去へ戻す操作で、AI に渡してよいものではない。
         * `verify` は何も変えず、「戻せる backup があるか」に答えられると相談に役立つ。
         */
        'backup.list' => OperationClassification::Read,
        'backup.verify' => OperationClassification::Read,

        /*
         * フォーム（Phase 9.12 / P0-8・SPEC §E.2）。**定義の参照だけ**。
         *
         * 受信（`form.submission.*`）は出さない。本文と連絡先そのものであり、
         * 9.10 で 404 の記録すら出さないと決めた基準に合わせる。
         * 書き込みも出さない — 外部入力の口の形を AI が変えてよい理由が無い。
         */
        'form.list' => OperationClassification::Read,
        'form.get' => OperationClassification::Read,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::OPERATIONS);
    }

    public static function allows(string $operationId): bool
    {
        return array_key_exists($operationId, self::OPERATIONS);
    }

    public static function classificationOf(string $operationId): ?OperationClassification
    {
        return self::OPERATIONS[$operationId] ?? null;
    }

    /**
     * allowlist の操作を 1 つ実行する。
     * 認可・検証・確認・監査はすべて Application Service 側で行われる。
     *
     * @param  array<string, mixed>  $input
     */
    public function invoke(string $operationId, array $input, OperationContext $context): Result
    {
        if (! self::allows($operationId)) {
            return Result::forbidden("操作 [{$operationId}] は AI から実行できません");
        }

        $str = static fn (string $key, string $default = ''): string => is_scalar($input[$key] ?? null)
            ? (string) $input[$key]
            : $default;
        $int = static fn (string $key, int $default = 0): int => is_numeric($input[$key] ?? null)
            ? (int) $input[$key]
            : $default;
        $arr = static fn (string $key): array => is_array($input[$key] ?? null) ? $input[$key] : [];

        return match ($operationId) {
            'content.types.list' => $this->container->get(ListContentTypes::class)->execute($context),
            'content.schema.get' => $this->container->get(GetContentSchema::class)->execute($str('type'), $context),
            'content.list' => $this->container->get(ListContent::class)
                ->execute($str('type'), $context, $str('q'), $int('page', 1), $int('perPage', 20)),
            'content.duplicate' => $this->container->get(DuplicateContent::class)->execute($str('type'), $int('id'), $context),
            'content.get' => $this->container->get(GetContent::class)->execute($str('type'), $int('id'), $context),
            // taxonomies は $arr() を通さない。**キーの有無だけ**を見て raw 値のまま渡す
            // （`taxonomies: null` や不正値を [] へ変換すると「黙って無視」になるため）
            'content.create' => $this->container->get(CreateContent::class)
                ->execute($str('type'), $arr('data'), $context, TaxonomyPayload::fromInput($input)),
            /*
             * **版を黙って捨てない**（Phase 9.8 / STAGE 21C 是正）。
             * MCP から送られた `lock_version` も、他の面と同じように Application 層まで運ぶ。
             */
            'content.update' => $this->container->get(UpdateContent::class)
                ->execute($str('type'), $int('id'), $arr('data'), $context, partial: true,
                    taxonomies: TaxonomyPayload::fromInput($input),
                    lockVersion: LockVersionInput::fromRequest($input)),
            'content.delete' => $this->container->get(DeleteContent::class)
                ->execute($str('type'), $int('id'), $context, LockVersionInput::fromRequest($input)),
            /*
             * **`afterId` は「無い」と「先頭へ」が同じ形**である（どちらも null）。
             * 先頭へ動かすのが最も普通の操作なので、**書かなければ先頭**にする。
             */
            // `afterId` は **潰さずに渡す**（F-39 — 読めるかは Application 層が決める）
            'content.reorder' => $this->container->get(ReorderContent::class)->execute(
                $str('type'), $int('id'), $input['afterId'] ?? null, $context,
            ),
            'content.trash' => $this->container->get(TrashContent::class)
                ->execute($str('type'), $int('id'), $context, LockVersionInput::fromRequest($input)),
            'media.list' => $this->container->get(ListMedia::class)->execute($context, $int('page', 1), $int('perPage', 24)),
            'media.get' => $this->container->get(GetMedia::class)->execute($int('id'), $context),
            'media.update' => $this->container->get(UpdateMedia::class)->execute($int('id'), $arr('data'), $context),
            'media.replace' => $this->replaceMedia($input, $context),
            'settings.get' => $this->container->get(GetSettings::class)->execute($context),
            'settings.update' => $this->container->get(UpdateSettings::class)->execute($arr('values'), $context, partial: true),
            // `contact.list` / `contact.get` の枝は置かない（B-43 — allowlist の項を見よ）
            'page.list' => $this->container->get(ListPages::class)->execute($context, $str('status')),
            // **入力をここで組み直さない**（Phase 9.3B / #17）。
            // キーを拾い直すと、禁止キー（managedBy 等）や未知キーが
            // Application Service へ届かず「送ったのに黙って捨てられた」状態になる。
            // 受理キーの判定・型の検証・拒否は CreatePage が一元的に行う契約
            'page.create' => $this->container->get(CreatePage::class)->execute($input, $context),
            'page.duplicate' => $this->container->get(DuplicatePage::class)->execute($int('pageId'), $context),
            'page.get' => $this->container->get(GetPage::class)->execute($int('id'), $context),
            'page.document.versions' => $this->container->get(ListPageVersions::class)->execute(
                $int('pageId'),
                $context,
                $int('perPage') ?: ListPageVersions::MAX_PER_PAGE,
                $int('before') ?: null,
            ),
            'page.document.get' => $this->container->get(GetPageDocument::class)->execute(
                $int('pageId'),
                $context,
                ($input['from'] ?? null) !== null ? $str('from') : null,
                is_numeric($input['depth'] ?? null) ? $int('depth') : null,
                (bool) ($input['published'] ?? false),
            ),
            'page.document.patch' => $this->container->get(PatchPageDocument::class)
                ->execute($int('pageId'), $arr('command'), $context),
            // `publishedAt` は **潰さずに渡す**（無ければ「いま」。読めるかは Application 層が決める）
            'page.publish' => $this->container->get(PublishPage::class)
                ->execute($int('pageId'), $context, $input[PublishPage::INPUT_KEY] ?? null),
            'page.unpublish' => $this->container->get(UnpublishPage::class)->execute($int('pageId'), $context),
            'page.trash' => $this->container->get(TrashPage::class)->execute($int('pageId'), $context),
            // 分類（Phase 9.3B）。入力キーは Application Service の contract と同じ名前を使う。
            // term.create / term.update は入力配列をそのまま渡し、**未知キーの拒否は
            // Application Service（TermInput::rejectUnknownKeys）に任せる**
            'form.list' => $this->container->get(ListForms::class)->execute($context),
            'form.get' => $this->container->get(GetForm::class)->execute($str('key'), $context),
            'backup.list' => $this->container->get(ListBackups::class)->execute($context),
            'backup.verify' => $this->container->get(VerifyBackup::class)->execute($str('id'), $context),
            'redirect.list' => $this->container->get(ListRedirects::class)
                ->execute($context, $str('q', ''), null, $int('page', 1), $int('perPage', 50)),
            'redirect.simulate' => $this->container->get(SimulateRedirect::class)
                ->execute($str('url'), $context),
            'menu.list' => $this->container->get(ListMenus::class)->execute($context),
            'menu.get' => $this->container->get(GetMenu::class)->execute($str('key'), $context),
            'taxonomy.list' => $this->container->get(ListTaxonomies::class)->execute($context),
            'taxonomy.get' => $this->container->get(GetTaxonomy::class)->execute($str('key'), $context),
            'term.list' => $this->container->get(ListTerms::class)->execute(
                $str('taxonomyKey'),
                $context,
                ($input['includeArchived'] ?? false) === true,
            ),
            'term.create' => $this->container->get(CreateTerm::class)->execute($input, $context),
            'term.update' => $this->container->get(UpdateTerm::class)->execute(
                $str('termId'),
                // termId は位置引数。属性として渡すと「未知のキー」になる
                array_diff_key($input, ['termId' => true]),
                $context,
            ),
            'term.reorder' => $this->container->get(ReorderTerms::class)->execute(
                $str('taxonomyKey'),
                is_string($input['parentId'] ?? null) ? (string) $input['parentId'] : null,
                $arr('orderedTermIds'),
                $context,
            ),
            'term.delete' => $this->container->get(DeleteTerm::class)->execute($str('termId'), $context),
            // allows() を通っているので到達しない。allowlist を増やしたら必ずここも足す
            default => Result::forbidden("操作 [{$operationId}] は AI から実行できません"),
        };
    }

    /**
     * 画像差し替え。**ファイルパスは受け取らない**（任意ファイル読み取りの入口を作らないため）。
     * 内容は base64 で受け取り、既存のアップロード検証（MIME 許可・SVG sanitize・上限）を通す。
     *
     * @param  array<string, mixed>  $input
     */
    private function replaceMedia(array $input, OperationContext $context): Result
    {
        $id = is_numeric($input['id'] ?? null) ? (int) $input['id'] : 0;
        $filename = is_string($input['filename'] ?? null) ? basename($input['filename']) : '';
        $encoded = is_string($input['contentBase64'] ?? null) ? $input['contentBase64'] : '';

        if ($filename === '' || $encoded === '') {
            return Result::validationError([
                'contentBase64' => ['画像の内容（base64）とファイル名が必要です'],
            ]);
        }

        $binary = base64_decode($encoded, true);

        if ($binary === false || $binary === '') {
            return Result::validationError(['contentBase64' => ['base64 として読み取れません']]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'agentcms-media-');

        if ($tmp === false || file_put_contents($tmp, $binary) === false) {
            return Result::conflict('一時ファイルを作成できませんでした');
        }

        try {
            // test mode = true: 検証は UploadValidator / ImageProcessor が行う
            $file = new UploadedFile($tmp, $filename, null, null, true);

            return $this->container->get(ReplaceMedia::class)->execute($id, $file, $context);
        } finally {
            @unlink($tmp);
        }
    }
}
