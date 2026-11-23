<?php

declare(strict_types=1);

namespace App\Support;

/**
 * operation id → 必要な capability（PHASE WP-BASELINE / STAGE #18A）。
 *
 * **この 1 箇所だけが対応表である。** Admin / API / MCP のどこから来ても
 * `RoleOperationPolicy` はここを見るので、画面で隠したかどうかに関係なく
 * 同じ判定になる。
 *
 * ## 未知の operation は default deny
 *
 * 表に無い operation は、利用者 actor に対して**必ず拒否**する。
 * 新しい Application Service を足したときに「気づかないまま
 * 全員が実行できる」状態を作らないための既定である
 * （CLI / System は従来どおりサーバー管理者の実行として許可する）。
 */
final class OperationCapabilities
{
    /**
     * @var array<string, Capability>
     */
    private const MAP = [
        // ── 読み取り（管理画面を開くだけで通る操作） ─────────────
        'content.types.list' => Capability::ContentRead,

        'content.schema.get' => Capability::ContentRead,
        'content.list' => Capability::ContentRead,
        'component.reusable.list' => Capability::ContentRead,
        'content.get' => Capability::ContentRead,
        'page.list' => Capability::ContentRead,
        'page.get' => Capability::ContentRead,
        // 版の一覧は「いつ・誰が」を読むだけ（中身は返さない — §4.12）
        'page.document.versions' => Capability::ContentRead,
        'page.document.get' => Capability::ContentRead,
        'taxonomy.list' => Capability::ContentRead,
        'taxonomy.get' => Capability::ContentRead,
        'term.list' => Capability::ContentRead,
        'media.list' => Capability::ContentRead,
        'media.get' => Capability::ContentRead,
        // サイト設定の **読み取り**は閲覧者にも許す（個人情報を含まない）
        'settings.get' => Capability::ContentRead,

        // ── 日常のコンテンツ操作 ────────────────────────────────
        'content.create' => Capability::ContentWrite,
        'content.update' => Capability::ContentWrite,
        'page.create' => Capability::ContentWrite,
        'page.duplicate' => Capability::ContentWrite,
        'page.update' => Capability::ContentWrite,
        'page.document.patch' => Capability::ContentWrite,
        /*
         * 再利用 component（Phase 9.6 / P0-C7・SPEC §5.2）。
         *
         * **専用の capability は作らない**（Owner Gate V13 = 現状維持）。
         * 作るなら 1 か所（ここ）の差し替えで済む形にしてある。
         */
        'component.reusable.create' => Capability::ContentWrite,
        'component.reusable.update' => Capability::ContentWrite,
        // 公開は「いま公開されている見た目が変わる」操作。確認は Application 側で必ず通す
        'component.reusable.publish' => Capability::ContentWrite,
        // 復元は編集中の内容を置き換える。公開面は変わらないが、失われるものがある
        'page.document.restore' => Capability::ContentWrite,
        'page.publish' => Capability::ContentWrite,
        'page.unpublish' => Capability::ContentWrite,
        'page.restore' => Capability::ContentWrite,
        'term.create' => Capability::ContentWrite,
        'term.update' => Capability::ContentWrite,
        'term.reorder' => Capability::ContentWrite,
        // 掲載順（P1 ②）。**内容ではなく並びを変える**が、書けない人には触らせない
        'content.reorder' => Capability::ContentWrite,
        'media.upload' => Capability::ContentWrite,
        'media.update' => Capability::ContentWrite,
        'media.replace' => Capability::ContentWrite,

        // ── 削除・ゴミ箱 ───────────────────────────────────────
        'content.delete' => Capability::ContentDelete,
        // ゴミ箱への移動は削除と同じ重み（呼ばれた名前が違うだけで、できることは同じ）
        'content.trash' => Capability::ContentDelete,
        /*
         * 戻すのは書き込みである（Phase 9.8 / OG3 = (a)）。
         * catalog へは載せない — AI へ出すのは後からでもできる。
         */
        'content.restore' => Capability::ContentWrite,
        // 複製は新しい下書きの作成であり、公開権限にはならない。
        'content.duplicate' => Capability::ContentWrite,
        /*
         * 編集中の内容を履歴へ積む（Phase 9.15 / STAGE 28B）。**catalog へは載せない。**
         *
         * 自動保存は「編集 session の途中経過」であり、**AI には編集 session が無い**。
         * AI が内容を変えたいなら `content.update` が既に在る。
         */
        'content.autosave' => Capability::ContentWrite,
        /*
         * 過去の版への復元と履歴の閲覧（Phase 9.8 / STAGE 21D・OG3 = (a)）。
         * **どちらも catalog へは載せない**（AI / MCP へ公開しない）。
         */
        'content.revision.restore' => Capability::ContentWrite,
        'content.revision.list' => Capability::ContentRead,
        'content.revision.baseline' => Capability::ContentWrite,
        'page.trash' => Capability::ContentDelete,
        // しまうだけで消さないが、参照が残っていれば断る（§4.5）
        'component.reusable.archive' => Capability::ContentDelete,
        'term.delete' => Capability::ContentDelete,
        'media.delete' => Capability::ContentDelete,

        // ── 問い合わせ（本文に個人情報がある） ──────────────────
        'contact.list' => Capability::ContactRead,
        'contact.get' => Capability::ContactRead,
        'contact.updateStatus' => Capability::ContactWrite,

        // ── 設定 ───────────────────────────────────────────────
        'settings.update' => Capability::SettingsManage,

        // ── 投稿タイプ（DB とファイルを不可逆に変える） ──────────
        // 一覧も **管理画面そのもの**なので同じ権限にする。
        // AI / API 向けの型一覧（content.types.list）は読み取りのままで、
        // 「どんな型があるか」を知る経路は塞がない
        'content.types.definitions' => Capability::ContentTypeManage,
        'content.type.create' => Capability::ContentTypeManage,
        // 投稿タイプの lifecycle（Phase 9.7 / STAGE 20C）。
        // **entry の CRUD 権限とは分ける** — 記事を書ける人が型を無効にできてよいわけではない
        'content.type.update' => Capability::ContentTypeManage,
        'content.type.archive' => Capability::ContentTypeManage,
        'content.type.restore' => Capability::ContentTypeManage,
        'content.type.reorder' => Capability::ContentTypeManage,
        // 型側のコメント利用可否（Phase 9.7 / STAGE 20E）。受付の実体は P0-9
        'content.type.comments' => Capability::ContentTypeManage,
        // 分類の定義（Phase 9.7 / STAGE 20D）。
        // **項目（term）の権限とは別**。項目を足せる人が分類そのものを消せてよいわけではない
        'taxonomy.definition.create' => Capability::ContentTypeManage,
        'taxonomy.definition.update' => Capability::ContentTypeManage,
        'taxonomy.definition.archive' => Capability::ContentTypeManage,
        'taxonomy.definition.restore' => Capability::ContentTypeManage,
        'taxonomy.definition.delete' => Capability::ContentTypeManage,
        'taxonomy.definition.attach' => Capability::ContentTypeManage,
        'taxonomy.definition.detach' => Capability::ContentTypeManage,
        // カスタムフィールド（Field Group — Phase 9.5-A）。
        // 投稿タイプと同じ「構造を変える権限」の下に置く（ADR-0015 / SPEC §7.2）。
        // 9.5-A は一覧の読み取りだけだが、**画面そのもの**なので同格にする
        'field_group.list' => Capability::ContentTypeManage,
        'field_group.get' => Capability::ContentTypeManage,
        'field_group.create' => Capability::ContentTypeManage,
        'field_group.update' => Capability::ContentTypeManage,
        'field_group.archive' => Capability::ContentTypeManage,
        'field_group.restore' => Capability::ContentTypeManage,
        'field_group.reorder' => Capability::ContentTypeManage,

        // ── メニュー / ナビゲーション（Phase 9.9 / STAGE 22B） ──
        /*
         * **読みと書きで重みが違う。**
         *
         * 一覧と取得は AI へも公開する（SPEC §E.3）。MCP の actor は
         * Administrator として評価されるので、ここを `ContentTypeManage`
         * （Owner だけが持つ）にすると **公開したのに誰も呼べない**ことになる。
         *
         * 書き込みは**サイトの設定**と同じ重みにする（Owner 追加決定 2026-08-17）。
         * ヘッダーの編集に Owner を要求するのは運用に合わない。
         */
        'menu.list' => Capability::ContentRead,
        'menu.get' => Capability::ContentRead,
        'menu.save' => Capability::SettingsManage,
        'menu.delete' => Capability::SettingsManage,

        // ── リダイレクトと 404 の記録（Phase 9.10 / P0-5） ──────
        /*
         * メニューと同じ分け方をする（9.9 §E.3）。
         *
         * 読みは AI へも公開する（`redirect.list` / `redirect.simulate`）。
         * MCP の actor は Administrator として評価されるので、Owner しか持たない
         * 権限へ置くと「公開したのに誰も呼べない」ことになる。
         *
         * 書き込みはサイトの入口を変えるので、サイトの設定と同じ重みにする。
         * **404 の記録は AI へ出さない** — referrer と user-agent は個人に近づく。
         */
        'redirect.list' => Capability::ContentRead,
        'redirect.get' => Capability::ContentRead,
        'redirect.simulate' => Capability::ContentRead,
        'redirect.save' => Capability::SettingsManage,
        'redirect.delete' => Capability::SettingsManage,
        'not_found.list' => Capability::SettingsManage,
        'not_found.resolve' => Capability::SettingsManage,

        // ── フォーム（Phase 9.12 / P0-8） ──────────────────────
        /*
         * **既存の問い合わせと同じ軸に揃える。** 「問い合わせを見られる人」は
         * すでに定義されているので、新しい軸を作らない（SPEC §E.5）。
         *
         * 定義（フォームの形）は外部入力の口を変える操作なので `SettingsManage`。
         */
        'form.list' => Capability::ContentRead,
        'form.get' => Capability::ContentRead,
        'form.save' => Capability::SettingsManage,
        /*
         * 共有している入力項目を、このフォームだけのものにする（B-31）。
         *
         * **`form.save` と同じ重みにする。** フォームを作ると専用の束が 1 つ付いてくる
         * （R-8A ①）のと同じことをしているので、ここだけ `ContentTypeManage` を要求すると
         * **「フォームは作れるが、既存フォームの項目は直せない」**という運用になる。
         */
        'form.field_group.detach' => Capability::SettingsManage,
        'form.delete' => Capability::SettingsManage,
        'form.submission.list' => Capability::ContactRead,
        'form.submission.get' => Capability::ContactRead,
        'form.submission.status' => Capability::ContactWrite,
        'form.submission.export' => Capability::ContactRead,
        // 受信を消すのは「対応する人」の仕事（状態変更と同じ軸。専用の権限を増やさない）
        'form.submission.delete' => Capability::ContactWrite,

        /*
         * コメント（Phase 9.13 / P0-9・SPEC §E.1）。
         *
         * **承認は `CommentModerate`、記事ごとの受付は `ContentWrite`。**
         * 前者は他人の発言を公開するか消すかの判断、後者は自分の記事の設定である。
         */
        'comment.list' => Capability::CommentModerate,
        'comment.get' => Capability::CommentModerate,
        'comment.status' => Capability::CommentModerate,
        'comment.reply' => Capability::CommentModerate,
        'comment.entry.open' => Capability::ContentWrite,

        // ── backup（Phase 9.11 / P0-6） ────────────────────────
        /*
         * 読みは AI へも公開する（`backup.list` / `backup.verify`）。何も変えない。
         *
         * **復元と持ち出しだけ Owner 限定にする**（`BackupRestore`）。
         * 復元はサイト全体を過去へ戻し、持ち出しは利用者のパスワード hash と
         * 問い合わせの本文を含む塊を外へ出す。設定変更と同じ重みにはできない。
         */
        'backup.list' => Capability::ContentRead,
        'backup.verify' => Capability::ContentRead,
        'backup.delete' => Capability::SettingsManage,
        'backup.restore' => Capability::BackupRestore,
        'backup.download' => Capability::BackupRestore,
        /*
         * 画面からの取得と、その進みの閲覧も **Owner**（2026-09-09・OG3 改訂）。
         * 取得はサーバーで別プロセスを起こし、パスワード hash と問い合わせ本文を含む
         * 塊を作る。「サイト設定を変えられる人」より重い操作として、復元と同じ側に置く。
         */
        'backup.create' => Capability::BackupRestore,
        'backup.run.status' => Capability::BackupRestore,

        /*
         * 本文の一括置換（P1 ⑧ / GUI 化・2026-09-10）。**Owner だけ。catalog へは載せない。**
         *
         * 予告・実行のどちらも同じ operation で判定する（予告にも本文の抜粋が出る）。
         * 画面で入口を隠すのは補助で、`ReplaceContent` がこの表で断る。
         */
        'content.replace' => Capability::ContentReplace,

        // ── 利用者の管理 ───────────────────────────────────────
        'user.list' => Capability::UserManage,
        'user.get' => Capability::UserManage,
        'user.invite' => Capability::UserManage,
        'user.update' => Capability::UserManage,
        'user.role.change' => Capability::UserManage,
        'user.deactivate' => Capability::UserManage,
        'user.reactivate' => Capability::UserManage,
        'user.invitation.resend' => Capability::UserManage,
        'user.setup_link.issue' => Capability::UserManage,

        // ── 操作履歴 ───────────────────────────────────────────
        'activity.list' => Capability::ActivityRead,
        'activity.export' => Capability::ActivityRead,
    ];

    /** 表に無ければ null = 利用者 actor に対しては拒否 */
    public static function for(string $operationId): ?Capability
    {
        return self::MAP[$operationId] ?? null;
    }

    /** @return list<string> */
    public static function operationIds(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * 権限表（画面・テスト・報告で共有する）。
     *
     * @return array<string, string> operation id => capability value
     */
    public static function all(): array
    {
        return array_map(static fn (Capability $c): string => $c->value, self::MAP);
    }
}
