<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Wxr\Status\WxrStatusColumn;

/**
 * Content Type ID と、そこから決定的に導かれる名前（Phase 9.4 / STAGE #17A）。
 *
 * ID は **immutable** で、次の 4 つの identifier をすべて決める。
 *
 * | 導出先 | 例（id = `faq`） |
 * | --- | --- |
 * | テーブル名 | `faq` |
 * | モジュール名 | `Faq` |
 * | モデル FQCN | `Modules\Faq\Models\Faq` |
 * | 管理画面 route base | `faq`（`/admin/faq`） |
 *
 * ここが **唯一の検証点**である。path traversal・SQL 識別子注入・
 * PHP namespace / class 名注入は、文法を通す前に構造的に不可能にする。
 * `preg_match` で許すのは `[a-z][a-z0-9_]{1,39}` だけなので、
 * `/`・`\`・`.`・`..`・NUL・引用符・空白・非 ASCII は **一切通らない**。
 */
final class ContentTypeIdentifier
{
    /**
     * 先頭は英小文字、以降は英小文字・数字・アンダースコアのみ。全体で 2〜40 文字。
     *
     * `\A` / `\z` を使う（`^` / `$` は改行を許してしまい、
     * `faq\n; DROP TABLE` のような入力が通る）。
     */
    public const PATTERN = '/\A[a-z][a-z0-9_]{1,39}\z/';

    /**
     * 管理画面 URL として使えない語。
     *
     * `/admin/<id>` を占有するため、既存の固定 route と衝突させない。
     *
     * @var list<string>
     */
    public const RESERVED_ROUTES = [
        'admin', 'api', 'appearance', 'build', 'contact', 'content_types', 'create', 'dashboard',
        'delete', 'edit', 'figma', 'login', 'logout', 'manifest', 'media', 'notifications',
        'offline', 'pages', 'password', 'post', 'posts', 'profile', 'register', 'security',
        'settings', 'site', 'storage', 'sw', 'taxonomies', 'terms', 'up', 'user', 'verify',
    ];

    /**
     * **システムが使う**テーブル名。
     *
     * ここに載せてよいのは、**どの生成プロジェクトにも必ず在る**土台の表だけである。
     * DB 上の実テーブル検査（`ContentTypePlanner`）と併用し、
     * ここは「まだ migrate されていない環境でも拒否する」ための静的な網。
     *
     * ## kit の例（news / works / products）を静的に予約しない（R-13 7 件目）
     *
     * 以前はこの 3 つも載せていた。**利用者のプロジェクトには配られない**（`distribution.ts`）ので、
     * **実在しないテーブル名で作成が必ず断られていた**。
     * 「投稿タイプ ID を works にした型を作ってください」という案内を書きながら、
     * その案内どおりにやると管理画面でも CLI でも作れない状態だった。
     *
     * 実在する環境（kit）では `ContentTypePlanner` が
     * 「schema ファイルがすでに存在します」「テーブル [works] はすでにデータベースに存在します」
     * と**理由を言って**断る。静的な網はもう要らない。
     *
     * @var list<string>
     */
    public const RESERVED_TABLES = [
        'audit_logs', 'cache', 'cache_locks', 'contact_submissions', 'content_types',
        'failed_jobs', 'job_batches', 'jobs', 'media', 'migrations',
        'notification_preferences', 'page_versions', 'pages', 'passkeys',
        'password_reset_tokens', 'post', 'posts', 'push_subscriptions', 'sessions',
        'settings', 'taxonomies', 'taxonomy_term_assignments', 'taxonomy_terms', 'users',
    ];

    /**
     * PHP の予約語（class 名にできない）。
     *
     * ID → StudlyCase でモジュール名 / class 名になるため、
     * `list` → `List` のような衝突を **生成前に**落とす。大小文字は区別しない。
     *
     * @var list<string>
     */
    public const RESERVED_PHP_WORDS = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum',
        'eval', 'exit', 'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof',
        'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace',
        'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch',
        'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /**
     * SQL 識別子として扱いを誤りやすい語。
     *
     * Laravel はテーブル名を quote するので **技術的には動く**が、
     * 生 SQL を書いた瞬間に壊れる名前をあえて許す理由がない。
     *
     * @var list<string>
     */
    public const RESERVED_SQL_WORDS = [
        'add', 'all', 'alter', 'and', 'as', 'asc', 'between', 'by', 'call', 'cascade', 'case',
        'column', 'constraint', 'create', 'cross', 'database', 'default', 'delete', 'desc',
        'describe', 'distinct', 'drop', 'else', 'exists', 'explain', 'false', 'for', 'foreign',
        'from', 'grant', 'group', 'having', 'if', 'ignore', 'in', 'index', 'inner', 'insert',
        'into', 'is', 'join', 'key', 'left', 'like', 'limit', 'lock', 'match', 'natural', 'not',
        'null', 'offset', 'on', 'or', 'order', 'outer', 'primary', 'references', 'rename',
        'replace', 'restrict', 'revoke', 'right', 'schema', 'select', 'set', 'show', 'table',
        'then', 'to', 'true', 'union', 'unique', 'update', 'usage', 'use', 'using', 'values',
        'when', 'where', 'with',
    ];

    /**
     * field key として使えない語。
     *
     * - `taxonomies` … 分類割り当て専用の予約キー（`ContentSchema::RESERVED_FIELD_KEYS`）
     * - `id` / `data` / `created_at` / `updated_at` / `deleted_at` … CMS が管理するカラム
     * - `editUrl` / `publicUrl` … 一覧が行へ差し込む導線（`SchemaResourceController`）
     * - `wp_status` … 取り込み元の公開状態（`WxrStatusColumn`）。field にすると
     *   **編集フォームの書き込み対象になり、人の編集で出自が消える**（SPEC §C.8.3）
     *
     * ゴミ箱の 3 列（`deleted_at` / `trashed_by` / `lock_version`）も同じ理由で
     * 断るが、**この一覧には書かない** — `ContentSchema::RESERVED_COLUMN_KEYS` を
     * `isValidFieldKey()` が参照する。一覧を 2 か所に持つと、片方だけ育つ。
     * - `confirmation_token` / `taxonomies_managed` … フォームの制御値
     *
     * @var list<string>
     */
    public const RESERVED_FIELD_KEYS = [
        'confirmation_token', 'created_at', 'data', 'deleted_at', 'editurl', 'id', 'password',
        'publicurl', 'remember_token', 'taxonomies', 'taxonomies_managed', 'updated_at',
        WxrStatusColumn::NAME,
    ];

    private function __construct(public readonly string $value) {}

    /**
     * **新しい** ID を検証して identifier を作る。**不正なら理由つきで null**。
     *
     * 「安全か」（構文・SQL / PHP の予約語）に加えて、**「空いているか」**
     * （管理画面 URL・既存テーブル名）まで見る。作成フォームと CLI はこちらを使う。
     *
     * @param  list<string>  $errors  失敗理由（呼び出し側の配列へ追記する）
     */
    public static function tryFrom(mixed $raw, array &$errors): ?self
    {
        $identifier = self::forRegistered($raw, $errors);

        if ($identifier === null) {
            return null;
        }

        $value = $identifier->value;

        if (in_array($value, self::RESERVED_ROUTES, true) || in_array(str_replace('_', '-', $value), self::RESERVED_ROUTES, true)) {
            $errors[] = "Content Type ID [{$value}] は管理画面の URL として予約されています";

            return null;
        }

        if (in_array($value, self::RESERVED_TABLES, true)) {
            $errors[] = "Content Type ID [{$value}] はシステムが使う名前です（別の ID にしてください）";

            return null;
        }

        return $identifier;
    }

    /**
     * **既に登録されている** ID を読む。構文と安全性だけを見る。
     *
     * 発見（`CmsManagedContentTypes::discover()`）と汎用モデル（`ManagedEntry`）はこちらを使う。
     * **「空いているか」を問わないのが `tryFrom()` との唯一の違い**である。
     *
     * 同梱の既定型を予約語へ足せるようにするために分けている。`post` は
     * `/admin/post` を占有するので **新しい ID としては予約**だが、
     * **その型自身は読めなければならない**。1 つのメソッドで両方は満たせない。
     *
     * 安全性の検査（PATTERN / SQL 予約語 / PHP 予約語）は**どちらの入口でも同じ**である。
     * ここを緩めると、テーブル名・クラス名・ファイル名の組み立てが壊れる。
     *
     * @param  list<string>  $errors  失敗理由（呼び出し側の配列へ追記する）
     */
    public static function forRegistered(mixed $raw, array &$errors): ?self
    {
        if (! is_string($raw)) {
            $errors[] = 'Content Type ID は文字列で指定してください';

            return null;
        }

        // trim しない。前後の空白は「見えない差」なので、直して通すのではなく落とす
        if (preg_match(self::PATTERN, $raw) !== 1) {
            $errors[] = 'Content Type ID は英小文字で始まり、英小文字・数字・アンダースコアだけを使う'
                .'2〜40 文字で指定してください（例: faq、event_report）';

            return null;
        }

        if (in_array($raw, self::RESERVED_SQL_WORDS, true)) {
            $errors[] = "Content Type ID [{$raw}] は SQL の予約語なのでテーブル名にできません";

            return null;
        }

        $studly = self::studlyOf($raw);

        if (in_array(strtolower($studly), self::RESERVED_PHP_WORDS, true)) {
            $errors[] = "Content Type ID [{$raw}] は PHP の予約語と衝突するためクラス名を作れません";

            return null;
        }

        return new self($raw);
    }

    /** field key の検証。ID と同じ文法に加えて、予約キーを拒否する */
    public static function isValidFieldKey(string $key): bool
    {
        if (preg_match(self::PATTERN, $key) !== 1) {
            return false;
        }

        if (in_array(strtolower($key), self::RESERVED_FIELD_KEYS, true)) {
            return false;
        }

        /*
         * **ContentSchema 側の予約キーを二重管理しない。**
         *
         * 列の予約（`RESERVED_COLUMN_KEYS`）もここで見る。一覧を書き写すと、
         * 片方に足してもう片方に忘れた列を Builder が通してしまい、
         * **生成された型が schema の構築時に例外で落ちる** — 作った人には
         * 「保存できたのに壊れた」に見える。参照すれば、その食い違いは起こらない。
         */
        return ! in_array($key, ContentSchema::RESERVED_FIELD_KEYS, true)
            && ! in_array(strtolower($key), ContentSchema::RESERVED_COLUMN_KEYS, true);
    }

    /** テーブル名（owner 合意: ID をそのまま使う） */
    public function table(): string
    {
        return $this->value;
    }

    /** モジュール名 / クラス名（`event_report` → `EventReport`） */
    public function studly(): string
    {
        return self::studlyOf($this->value);
    }

    /** @return class-string */
    public function modelClass(): string
    {
        /** @var class-string $class */
        $class = 'Modules\\'.$this->studly().'\\Models\\'.$this->studly();

        return $class;
    }

    /** 管理画面の route base（`/admin/<base>`） */
    public function routeBase(): string
    {
        return $this->value;
    }

    private static function studlyOf(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $value)));
    }
}
