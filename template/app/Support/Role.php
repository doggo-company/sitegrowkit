<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 利用者のロール（PHASE WP-BASELINE / STAGE #18A）。
 *
 * **固定の 4 つだけ**。Spatie 等の権限パッケージは入れない
 * （4 ロール + 既存 `OperationPolicy` で足りるので、依存を増やさない）。
 *
 * ここが役割名の **単一情報源**である。DB には `value` を保存し、
 * 画面表示・権限判定・検証はすべてこの enum を経由する
 * （文字列 'owner' がコードのあちこちに散らばるのを防ぐ）。
 */
enum Role: string
{
    /** 店主。日々の操作のすべて（利用者の管理を含む）。サイトの作りの画面と一括置換は制作者に任せる */
    case Owner = 'owner';

    /**
     * サイトを作る人（UX 一周 2026-09-28）。オーナーと同じ操作に加えて、
     * 作りの画面（投稿タイプ・カスタムフィールド・一括置換など）を開ける。
     */
    case Developer = 'developer';

    /** 日常の管理操作と監査閲覧。ユーザー管理と Content Type 作成はできない */
    case Administrator = 'administrator';

    /** 投稿・ページ・分類項目・メディアの日常操作 */
    case Editor = 'editor';

    /** 許可された画面の読み取りのみ */
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'オーナー',
            self::Developer => '制作者',
            self::Administrator => '管理者',
            self::Editor => '編集者',
            self::Viewer => '閲覧者',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => '店主。記事・ページ・設定・利用者の管理など、日々の操作のすべて。サイトの作りの画面は制作者が受け持ちます',
            self::Developer => 'サイトを作る人。オーナーと同じ操作に加えて、投稿タイプ・カスタムフィールド・一括置換など作りの画面を開けます',
            self::Administrator => 'コンテンツ・サイト設定・操作履歴。利用者の管理はできません',
            self::Editor => '投稿・ページ・分類・メディアの日常操作',
            self::Viewer => '閲覧のみ。内容を変更できません',
        };
    }

    /**
     * このロールが持つ権限。
     *
     * **権限表の単一情報源**（`OperationCapabilities` が operation → capability を、
     * ここが role → capability を持つ）。
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            // 作りの画面（投稿タイプ・一括置換など）は制作者だけ。店主のメニューにも出さず、URL でも開けない
            self::Owner => self::allExceptBuild(),
            self::Developer => Capability::cases(),
            self::Administrator => [
                Capability::ContentRead,
                Capability::ContentWrite,
                Capability::ContentDelete,
                Capability::ContactRead,
                Capability::ContactWrite,
                Capability::SettingsManage,
                Capability::ActivityRead,
                Capability::CommentModerate,
            ],
            // 日常のコンテンツ操作だけ。問い合わせ本文（PII）・設定・履歴は見せない
            self::Editor => [
                Capability::ContentRead,
                Capability::ContentWrite,
                Capability::ContentDelete,
                // 記事を書く人が自分の記事のコメントを捌けないと運用が回らない
                Capability::CommentModerate,
            ],
            self::Viewer => [
                Capability::ContentRead,
            ],
        };
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * 画面へ渡す選択肢（表示順は宣言順で固定する）
     *
     * @return list<array<string, string>>
     */
    public static function options(): array
    {
        return array_map(static fn (self $role): array => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
        ], self::cases());
    }

    /** 不明な値は **昇格させない**。読み取り専用へ倒す */
    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Viewer;
    }

    /**
     * 作りの権限を除いた全部（オーナー）。
     *
     * - `SiteBuild` — 作りの画面（route の `site.build` 関所）
     * - `ContentReplace` — 本文の一括置換。取り返しがつかないので、サイトを作る人だけ（2026-09-28）
     *
     * @return list<Capability>
     */
    private static function allExceptBuild(): array
    {
        $out = [];

        foreach (Capability::cases() as $capability) {
            if ($capability !== Capability::SiteBuild && $capability !== Capability::ContentReplace) {
                $out[] = $capability;
            }
        }

        return $out;
    }
}
