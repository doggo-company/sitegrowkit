<?php

declare(strict_types=1);
use Modules\News\Models\News;
use Modules\Products\Models\Product;
use Modules\Works\Models\Work;

/**
 * kit の例として同梱している、**コードで管理する** collection 型（PHASE 9.7 / STAGE 20F）。
 *
 * **このファイルは新しく生成するサイトへ配らない**（SPEC §C.5 / OG-A）。
 * 配らないものへの参照を、配るファイルの中に残さないための置き場所である。
 * ここに書くのは「schema id => モデル」だけで、在るかどうかの判断は読み手が行う。
 *
 * 管理画面の Content Type Builder が作った型（`managedBy: "cms"`）はここに書かない。
 * schema から実行時に発見する（`CmsManagedContentTypes`）。
 *
 * @return array<string, class-string>
 */
return [
    'news' => News::class,
    'works' => Work::class,
    'product' => Product::class,
];
