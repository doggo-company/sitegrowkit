<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 権限の単位（PHASE WP-BASELINE / STAGE #18A）。
 *
 * operation id を直接ロールへ結びつけない。間に capability を挟むことで、
 *
 * - 操作が増えても対応表 1 行で済む（`OperationCapabilities`）
 * - ロールの定義が「何ができるか」の言葉で読める（`Role::capabilities()`）
 *
 * ようにする。**未知の operation はどの capability にも紐づかず、
 * 利用者 actor に対しては拒否される**（default deny）。
 */
enum Capability: string
{
    /** 管理画面の閲覧と読み取り操作 */
    case ContentRead = 'content.read';

    /** 投稿・ページ・分類項目・メディアの作成と更新 */
    case ContentWrite = 'content.write';

    /** 上記の削除・ゴミ箱・公開の取り消し */
    case ContentDelete = 'content.delete';

    /** 問い合わせの閲覧（本文に個人情報が含まれる） */
    case ContactRead = 'contact.read';

    /** 問い合わせの対応状況の変更 */
    case ContactWrite = 'contact.write';

    /** サイト設定・通知設定の変更 */
    case SettingsManage = 'settings.manage';

    /** 投稿タイプ（Content Type）の作成と、カスタムフィールド（Field Group）の管理 */
    case ContentTypeManage = 'content_type.manage';

    /** 利用者の招待・ロール変更・無効化 */
    case UserManage = 'user.manage';

    /**
     * backup の復元と持ち出し（Phase 9.11 / P0-6）。
     *
     * **Owner だけが持つ。** ほかの権限と分けているのは、この 2 つだけ性質が違うからである。
     * 復元は**サイト全体を過去へ戻す**（取り返しがつかない）。
     * 持ち出しは**利用者のパスワード hash と問い合わせの本文を含む塊を外へ出す**。
     * 「サイトの設定を変えられる人」と同じ重みにはできない。
     */
    case BackupRestore = 'backup.restore';

    /**
     * 本文の一括置換（P1 ⑧ / GUI 化・2026-09-10）。
     *
     * **制作者だけが持つ**（2026-09-28 まではオーナーも）。元の文字列はどこにも残らず、版と backup からしか戻れない。
     * 「記事を書ける人」や「設定を変えられる人」と同じ重みにはできない。
     * `BackupRestore` に相乗りさせないのは、断る文面が「backup の復元」では
     * 何を求められているか分からないからである。
     */
    case ContentReplace = 'content.replace';

    /** 操作履歴の閲覧と書き出し（他人の履歴を含む） */
    case ActivityRead = 'activity.read';

    /**
     * コメントの承認・スパム判定・ゴミ箱・返信（Phase 9.13 / P0-9）。
     *
     * **`ContentWrite` から分けている。** 流用すると
     * 「記事を書ける人 ＝ 他人の文章を消せる人」になる。
     * 承認は**他人の発言を公開する**判断で、記事を書くこととは重みが違う。
     */
    case CommentModerate = 'comment.moderate';

    /**
     * サイトの作りの画面を管理メニューに出す（UX 一周 2026-09-28・メンテナの方針）。
     *
     * 投稿タイプ・カスタムフィールド・再利用コンポーネント・分類・リダイレクトなど、
     * **サイトを作る人のための画面**。店主の管理メニューには出さない（迷わせない）。
     * **制作者だけが持つ。** メニューに並べるかに加えて、route の `site.build` 関所
     * （`RequireSiteBuild`）で URL を直接開いても入れない（2026-09-28）。
     */
    case SiteBuild = 'site.build';

    public function label(): string
    {
        return match ($this) {
            self::CommentModerate => 'コメントの承認・削除',
            self::SiteBuild => 'サイトの作りの画面（投稿タイプ・カスタムフィールドなど）',
            self::ContentRead => 'コンテンツの閲覧',
            self::ContentWrite => 'コンテンツの作成・編集',
            self::ContentDelete => 'コンテンツの削除',
            self::ContactRead => 'お問い合わせの閲覧',
            self::ContactWrite => 'お問い合わせの対応',
            self::SettingsManage => 'サイト設定・通知設定',
            self::ContentTypeManage => '投稿タイプ・カスタムフィールドの管理',
            self::UserManage => '利用者の管理',
            self::ActivityRead => '操作履歴の閲覧',
            self::BackupRestore => 'backup の復元と持ち出し',
            self::ContentReplace => '本文の一括置換',
        };
    }
}
