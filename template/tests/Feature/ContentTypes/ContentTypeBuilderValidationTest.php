<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\ContentTypes\ContentTypeDraft;
use AgentCms\Core\ContentTypes\ContentTypeGenerator;

/**
 * Phase 9.4 / STAGE #17A — 受け付けてはいけない入力（SPEC §2-10〜18・25）。
 *
 * **検証に失敗した入力は、ファイルも DB も一切変えない**。
 * どの拒否も「黙って直して通す」ことはしない（直すと利用者の意図とずれる）。
 */
final class ContentTypeBuilderValidationTest extends BuilderTestCase
{
    /** SPEC §2-10 */
    public function test_重複するidを拒否する(): void
    {
        $this->assertRejected(['id' => 'news'], 'id');
        $this->assertRejected(['id' => 'works'], 'id');
        $this->assertRejected(['id' => 'product'], 'id');
    }

    /** SPEC §2-11 — 既存テーブルと衝突する ID */
    public function test_重複するテーブル名を拒否する(): void
    {
        // schema id とテーブル名が違う型（product → products）も塞ぐ
        $this->assertRejected(['id' => 'products'], 'id');
        $this->assertRejected(['id' => 'users'], 'id');
        $this->assertRejected(['id' => 'migrations'], 'id');
        $this->assertRejected(['id' => 'audit_logs'], 'id');
    }

    /** SPEC §2-12 */
    public function test_route予約語を拒否する(): void
    {
        foreach (['media', 'pages', 'taxonomies', 'terms', 'contact', 'site', 'notifications', 'create', 'dashboard'] as $reserved) {
            $this->assertRejected(['id' => $reserved], 'id');
        }
    }

    /** SPEC §2-13 — path traversal / 区切り文字 / NUL / Unicode 偽装 */
    public function test_パス操作や偽装文字を拒否する(): void
    {
        foreach ([
            '../faq',
            '..',
            'faq/../etc',
            'faq/sub',
            'faq\\sub',
            "faq\nnews",
            'ｆａｑ',          // 全角英字
            'FAQ',             // 大文字
            'f',               // 短すぎる
            str_repeat('a', 41), // 長すぎる
            '1faq',            // 数字始まり
            'faq-list',        // ハイフン
            'fa.q',
        ] as $id) {
            $this->assertRejected(['id' => $id], 'id');
        }
    }

    /**
     * SPEC §2-13（続き）— web の正規化に頼らずドメイン側で落ちること。
     *
     * `TrimStrings` は前後の空白と NUL を落とすので HTTP からは届かないが、
     * CLI / 直接呼び出しでも同じ判断になることを固定する。
     */
    public function test_前後の空白やnul文字をドメイン側で拒否する(): void
    {
        foreach (['faq ', ' faq', "faq\0", "\0faq", "faq\t"] as $id) {
            $this->assertDraftRejected(['id' => $id], 'id');
        }
    }

    /** SPEC §2-14 — SQL / PHP / HTML / JavaScript の注入文字列 */
    public function test_注入文字列を拒否する(): void
    {
        foreach ([
            'faq; DROP TABLE users',
            "faq'--",
            'faq`',
            'faq"',
            '<?php system("id"); ?>',
            '<script>alert(1)</script>',
            'faq*/',
            'faq${x}',
            'faq|id',
            'faq&&id',
        ] as $id) {
            $this->assertRejected(['id' => $id], 'id');
        }
    }

    /** SPEC §2-15 — `taxonomies` などの予約 field key */
    public function test_予約されたfield_keyを拒否する(): void
    {
        foreach (['taxonomies', 'id', 'data', 'created_at', 'updated_at', 'deleted_at'] as $key) {
            $this->assertRejected([
                'fields' => [
                    ['key' => $key, 'type' => 'text', 'label' => 'x', 'choices' => []],
                ],
                'list' => [],
                'search' => [],
            ], 'fields.0.key');
        }
    }

    public function test_不正な文法のfield_keyを拒否する(): void
    {
        foreach (['../x', 'a b', 'Ａ', 'A', '1a', 'a-b', "a\0b"] as $key) {
            $this->assertRejected([
                'fields' => [
                    ['key' => $key, 'type' => 'text', 'label' => 'x', 'choices' => []],
                ],
                'list' => [],
                'search' => [],
            ], 'fields.0.key');
        }
    }

    /** SPEC §2-16 */
    public function test_field_keyの重複を拒否する(): void
    {
        $this->assertRejected([
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'A', 'choices' => []],
                ['key' => 'title', 'type' => 'textarea', 'label' => 'B', 'choices' => []],
            ],
            'list' => [],
            'search' => [],
        ], 'fields.1.key');
    }

    /** SPEC §2-17 */
    public function test_choices必須型で空choicesを拒否する(): void
    {
        foreach (['select', 'radio', 'checkbox'] as $type) {
            $this->assertRejected([
                'fields' => [
                    ['key' => 'kind', 'type' => $type, 'label' => '種別', 'choices' => []],
                ],
                'list' => [],
                'search' => [],
            ], 'fields.0.choices');

            // 空白だけの選択肢も「無い」と同じ扱いにする
            $this->assertRejected([
                'fields' => [
                    ['key' => 'kind', 'type' => $type, 'label' => '種別', 'choices' => ['', '   ']],
                ],
                'list' => [],
                'search' => [],
            ], 'fields.0.choices');
        }
    }

    /** SPEC §2-18 — Builder が扱わない型 */
    public function test_未対応のfield_typeを拒否する(): void
    {
        foreach (['image', 'images', 'file', 'relation', 'relations', 'group', 'repeater', 'json', 'blob'] as $type) {
            $this->assertRejected([
                'fields' => [
                    ['key' => 'extra', 'type' => $type, 'label' => 'X', 'choices' => []],
                ],
                'list' => [],
                'search' => [],
            ], 'fields.0.type');
        }
    }

    /**
     * SPEC §2-25 — XSS payload は **文字列として保存され、実行されない**。
     *
     * 消したり書き換えたりはしない（利用者が入力した文字はそのまま保つ）。
     * 保存先は Schema JSON だけで、生成される PHP には 1 文字も入らない。
     * 画面では Vue がテキストとして描画するのでエスケープされる
     * （ブラウザ上での不発は E2E `content-type-builder.spec.ts` が確かめる）。
     */
    public function test_xss_payloadは文字列として扱われる(): void
    {
        $payload = $this->payload([
            'label' => '<script>alert(1)</script>',
            'fields' => [
                [
                    'key' => 'title', 'type' => 'text', 'choices' => [],
                    'label' => '<img src=x onerror=alert(1)>',
                    'help' => '"><svg onload=alert(1)>',
                ],
            ],
            'list' => ['title'],
            'search' => [],
        ]);

        $parsed = ContentTypeDraft::fromInput($payload);
        $this->assertNotNull($parsed['draft'], '通常の文字列として受理されるべき');

        $json = json_encode($parsed['draft']->toSchemaArray(), JSON_THROW_ON_ERROR);

        // JSON の値として入り、PHP / SQL のどちらへも出ていかない
        $this->assertStringNotContainsString('<?php', $json);
        $this->assertSame(
            '<script>alert(1)</script>',
            $parsed['draft']->label,
            'label は文字列としてそのまま保持される（実行も除去もしない）',
        );

        // 生成される PHP には自由入力が 1 文字も入らない
        $model = app(ContentTypeGenerator::class)
            ->modelSource($parsed['draft']);
        $this->assertStringNotContainsString('script', $model);
        $this->assertStringNotContainsString('onerror', $model);
        $this->assertStringNotContainsString('svg', $model);
    }

    public function test_改行や制御文字を含むlabelを拒否する(): void
    {
        $this->assertRejected(['label' => "よくある\n質問"], 'label');
        $this->assertRejected(['label' => "よくある\0質問"], 'label');
        $this->assertRejected(['label' => ''], 'label');
        $this->assertRejected(['label' => str_repeat('あ', 41)], 'label');
    }

    /** 一覧列 / 検索対象に存在しないキーを黙って捨てない */
    public function test_存在しないフィールドを一覧列に選べない(): void
    {
        $this->assertRejected(['list' => ['title', 'ghost']], 'list');
        $this->assertRejected(['search' => ['ghost']], 'search');
    }

    /** 検索対象にできない型（richtext 以外の非テキスト）は拒否する */
    public function test_検索対象にできない型を拒否する(): void
    {
        $this->assertRejected(['search' => ['status']], 'search');
    }

    /** MySQL の行サイズ上限（utf8mb4）に収まらない構成を、作る前に落とす */
    public function test_行サイズ上限を超える構成を拒否する(): void
    {
        $fields = [];

        for ($i = 0; $i < 60; $i++) {
            $fields[] = [
                'key' => "field_{$i}", 'type' => 'text', 'label' => "項目{$i}",
                'max' => 255, 'choices' => [],
            ];
        }

        $this->assertRejected(['fields' => $fields, 'list' => [], 'search' => []], 'fields');
    }

    /** 逆に、複数行テキストは TEXT カラムなので 60 件でも収まる */
    public function test_複数行テキストは60件でも行サイズに収まる(): void
    {
        $fields = [];

        for ($i = 0; $i < 60; $i++) {
            $fields[] = [
                'key' => "field_{$i}", 'type' => 'textarea', 'label' => "項目{$i}", 'choices' => [],
            ];
        }

        $parsed = ContentTypeDraft::fromInput($this->payload([
            'fields' => $fields, 'list' => [], 'search' => [],
        ]));

        $this->assertNotNull($parsed['draft'], '複数行テキスト 60 件は受理されるべき');
    }

    public function test_フィールドが0件の入力を拒否する(): void
    {
        $this->assertRejected(['fields' => [], 'list' => [], 'search' => []], 'fields');
    }

    /**
     * 未知のキーを**黙って捨てない**。
     *
     * 「送ったのに何も起きない」は、利用者にも AI にも
     * 「効いた」と誤解させる（既存 Application Service と同じ契約）。
     */
    public function test_未知のトップレベルキーを拒否する(): void
    {
        $this->assertRejected(['managedBy' => 'code'], 'id');
        $this->assertRejected(['table' => 'other_table'], 'id');
        $this->assertRejected(['kind' => 'settings'], 'id');
        $this->assertRejected(['taxonomies' => []], 'id');
    }

    public function test_未知のフィールドキーを拒否する(): void
    {
        foreach ([
            ['key' => 'title', 'type' => 'text', 'label' => 'X', 'choices' => [], 'unique' => true],
            ['key' => 'title', 'type' => 'text', 'label' => 'X', 'choices' => [], 'target' => 'works'],
            ['key' => 'title', 'type' => 'text', 'label' => 'X', 'choices' => [], 'id' => '01ABC'],
            ['key' => 'title', 'type' => 'text', 'label' => 'X', 'choices' => [], 'fieldset' => 'seo'],
        ] as $field) {
            $this->assertRejected([
                'fields' => [$field],
                'list' => [],
                'search' => [],
            ], 'fields.0');
        }
    }
}
