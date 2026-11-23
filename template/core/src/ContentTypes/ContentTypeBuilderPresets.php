<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use AgentCms\Core\Schema\ContentSchema;

/**
 * 作成フォームの初期提案（Phase 9.4 / STAGE #17A）。
 *
 * **提案であって強制ではない。** 利用者は作成前に足し引きできる（SPEC §1B）。
 * 検証は `ContentTypeDraft` / `FieldDraft` が行うので、ここは
 * 「何も考えずに作っても運用できる最小構成」を出すだけ。
 */
final class ContentTypeBuilderPresets
{
    /**
     * ニュース・実績と同じ骨格（title / slug / body / status / published_at）。
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultFields(): array
    {
        return [
            [
                'key' => 'title',
                'type' => BuilderFieldType::Text->value,
                'label' => 'タイトル',
                'required' => true,
                'max' => 120,
                'searchable' => true,
                'sortable' => true,
                'choices' => [],
                'help' => '',
                'filterable' => false,
            ],
            [
                'key' => 'slug',
                'type' => BuilderFieldType::Slug->value,
                'label' => 'スラッグ',
                'required' => true,
                'max' => 120,
                'searchable' => false,
                'sortable' => false,
                'filterable' => false,
                'choices' => [],
                'help' => '空のままにするとタイトルから自動生成されます',
            ],
            [
                'key' => 'body',
                'type' => BuilderFieldType::Richtext->value,
                'label' => '本文',
                'required' => false,
                'max' => null,
                'searchable' => true,
                'sortable' => false,
                'filterable' => false,
                'choices' => [],
                'help' => '',
            ],
            [
                'key' => 'status',
                'type' => BuilderFieldType::Status->value,
                'label' => 'ステータス',
                'required' => true,
                'max' => null,
                'searchable' => false,
                'sortable' => false,
                'filterable' => true,
                'choices' => [],
                'help' => '',
            ],
            [
                'key' => 'published_at',
                'type' => BuilderFieldType::Datetime->value,
                'label' => '公開日時',
                'required' => false,
                'max' => null,
                'searchable' => false,
                'sortable' => true,
                'filterable' => false,
                'choices' => [],
                'help' => '',
            ],
        ];
    }

    /**
     * 標準提案での一覧列（news / works と同じ並び）。
     *
     * @return list<string>
     */
    public static function defaultList(): array
    {
        return ['title', 'status', 'published_at'];
    }

    /**
     * 標準提案での検索対象（news / works と同じ）。
     *
     * @return list<string>
     */
    public static function defaultSearch(): array
    {
        return ['title', 'body'];
    }

    /**
     * 作成フォームの preset（Phase 9.7 / STAGE 20B・SPEC §C.5）。
     *
     * **preset は初期提案であって正本ではない。** 選んだ preset の名前は
     * どこにも保存しない — 保存すると「導出できるものを永続化する」ことになり、
     * あとから field を足した型が「news preset の型」と名乗り続ける。
     *
     * `news` / `works` は kit の例をなぞったもので、**その module を配ることとは別**である
     * （Owner Decision OG-A = (b)）。`product` は 20 種の field 型を網羅した
     * 検証用 fixture なので preset にしない。
     *
     * @return list<array{id: string, label: string, description: string, fields: list<array<string, mixed>>, list: list<string>, search: list<string>}>
     */
    public static function presets(): array
    {
        return [
            [
                'id' => 'blank',
                'label' => '空から作る',
                'description' => 'タイトルとスラッグだけ。必要な項目は Field Group で足します。',
                'fields' => array_values(array_filter(
                    self::defaultFields(),
                    static fn (array $field): bool => in_array($field['key'], ['title', 'slug'], true),
                )),
                'list' => ['title'],
                'search' => ['title'],
            ],
            [
                'id' => 'post',
                'label' => '投稿',
                'description' => 'タイトル・本文・公開状態を持つ、いちばん普通の投稿タイプです。',
                'fields' => [...self::defaultFields(), ...self::articleFields()],
                'list' => self::defaultList(),
                'search' => self::defaultSearch(),
            ],
            [
                'id' => 'news',
                'label' => 'お知らせ',
                'description' => '投稿に SEO 用の項目を足したものです。',
                'fields' => [...self::defaultFields(), ...self::articleFields(), ...self::seoFields()],
                'list' => self::defaultList(),
                'search' => self::defaultSearch(),
            ],
            [
                'id' => 'works',
                'label' => '実績',
                'description' => 'メイン画像・カテゴリ・完了日を持つ、事例紹介向けの構成です。',
                'fields' => [...self::defaultFields(), ...self::worksFields()],
                'list' => ['title', 'category', 'status', 'published_at'],
                'search' => self::defaultSearch(),
            ],
        ];
    }

    /**
     * 作成フォームへ渡す定数一式。
     *
     * @return array<string, mixed>
     */
    public static function formOptions(): array
    {
        return [
            'fieldTypes' => BuilderFieldType::toFormOptions(),
            'defaultFields' => self::defaultFields(),
            'defaultList' => self::defaultList(),
            'defaultSearch' => self::defaultSearch(),
            'presets' => self::presets(),
            'idPattern' => '^[a-z][a-z0-9_]{1,39}$',
            /*
             * **画面にも両方を出す。** 断るのはサーバーだが、出さないと
             * 作った人は送信して初めて知る。`isValidFieldKey()` が見るものと
             * 同じ集合をここから配る。
             */
            'reservedFieldKeys' => array_values(array_unique([
                ...ContentTypeIdentifier::RESERVED_FIELD_KEYS,
                ...ContentSchema::RESERVED_COLUMN_KEYS,
            ])),
        ];
    }

    /** 投稿・お知らせを作った後に、画像用の項目を別途設計しなくて済むようにする。
     * @return list<array<string, mixed>>
     */
    private static function articleFields(): array
    {
        return [
            self::field('main_image_id', BuilderFieldType::Media, 'アイキャッチ画像'),
            self::field('excerpt', BuilderFieldType::Textarea, '抜粋', max: 300, searchable: true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function seoFields(): array
    {
        return [
            self::field('seo_title', BuilderFieldType::Text, 'SEOタイトル', max: 120),
            self::field('seo_description', BuilderFieldType::Textarea, 'SEOディスクリプション', max: 200),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function worksFields(): array
    {
        return [
            self::field('main_image_id', BuilderFieldType::Media, 'メイン画像'),
            self::field('category', BuilderFieldType::Select, 'カテゴリ', required: true, filterable: true, choices: ['web', 'graphic', 'branding']),
            self::field('completed_at', BuilderFieldType::Date, '完了日', sortable: true),
            self::field('excerpt', BuilderFieldType::Textarea, '概要', max: 300, searchable: true),
        ];
    }

    /**
     * preset の 1 項目。**`defaultFields()` と同じ形**にそろえる
     * （フォームが 2 種類の形を扱わなくて済むように）。
     *
     * @param  list<string>  $choices
     * @return array<string, mixed>
     */
    private static function field(
        string $key,
        BuilderFieldType $type,
        string $label,
        bool $required = false,
        ?int $max = null,
        bool $searchable = false,
        bool $sortable = false,
        bool $filterable = false,
        array $choices = [],
        string $help = '',
    ): array {
        return [
            'key' => $key,
            'type' => $type->value,
            'label' => $label,
            'required' => $required,
            'max' => $max,
            'searchable' => $searchable,
            'sortable' => $sortable,
            'filterable' => $filterable,
            'choices' => $choices,
            'help' => $help,
        ];
    }
}
