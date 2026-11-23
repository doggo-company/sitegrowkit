<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages;

use AgentCms\Core\Pages\Reading\TitleReading;

/**
 * 日本語のタイトルから、意味の分かる URL の下書きを作る（UX 一周 2026-09-28）。
 *
 * URL の欄を空のまま「会社概要」を作ると、`Str::slug` が空を返すので
 * **`/page` になっていた**。2 枚目は `/page-2`。どれがどのページか、URL から分からない。
 *
 * ここでは、**どの業種のサイトにもある定番のページ名**だけを英語の URL に当てる。
 * 当たらなければ、題をローマ字にする（トリミング → torimingu、施術のご案内 → shijutsu-no-go-annai）。
 * それでも決まらなければ空を返し、呼ぶ側が意味を持たない土台へ倒す。
 *
 * 漢字はここでは読まない（UX 一周 17 周目 G1。以前は 1 字ずつの表で読み、変な綴りを作った）。画面が形態素解析で読む。
 * ページの作成の画面は、題を形態素解析（kuromoji・IPADIC）で読んだ候補を `slug_draft` で送る
 * （`resources/js/lib/reading-slug.mjs`。setup のフォルダ名の候補と同じ関数）。ここは画面を通らない
 * 作り方（API・エージェント）のための控えで、漢字を含む題は空を返し、呼ぶ側が土台（page）へ倒す。
 * 作成した画面に URL が出て、あとから変えられる。
 *
 * 🔴 **利用者が入れた URL は書き換えない。** 呼ぶのは URL が空のときだけ（`CreatePage`）。
 */
final class SlugFromTitle
{
    /**
     * 定番のページ名に付くありふれた付け足し（UX 一周 21 周目 Y1）。題名が「定番名＋これ」だけなら定番名の英語
     * （料金のご案内 → price・アクセス方法 → access）。それ以外（店名・地名・人名が付く）は全部読む。
     * setup の `reading-slug.mjs` の `COMMON_SUFFIXES` と同じ表。
     *
     * @var list<string>
     */
    public const COMMON_SUFFIXES = [
        'のご案内',
        'のご紹介',
        'のご説明',
        'ご案内',
        'ご紹介',
        'の案内',
        'の紹介',
        'の方法',
        'の一覧',
        'の情報',
        'について',
        '案内',
        '紹介',
        '方法',
        '一覧',
        '情報',
    ];

    /**
     * 定番のページ名 → URL。**長い名前を先に当てる**（「会社概要」より先に「会社」を当てない）。
     *
     * @var array<string, string>
     */
    public const COMMON = [
        '特定商取引法に基づく表記' => 'tokushoho',
        'プライバシーポリシー' => 'privacy-policy',
        '個人情報保護方針' => 'privacy-policy',
        'はじめての方へ' => 'first-visit',
        '初めての方へ' => 'first-visit',
        'よくあるご質問' => 'faq',
        'よくある質問' => 'faq',
        'お客様の声' => 'voice',
        'お問い合わせ' => 'contact',
        'お問合せ' => 'contact',
        '問い合わせ' => 'contact',
        '私たちについて' => 'about',
        '当店について' => 'about',
        'お店について' => 'about',
        'お店の紹介' => 'about',
        'お店の案内' => 'about',
        '店舗紹介' => 'about',
        '会社概要' => 'company',
        '会社案内' => 'company',
        '企業情報' => 'company',
        '事業内容' => 'service',
        'サービス' => 'service',
        '料金表' => 'price',
        '料金' => 'price',
        '価格' => 'price',
        'メニュー' => 'menu',
        'アクセス' => 'access',
        '店舗情報' => 'access',
        'スタッフ' => 'staff',
        '採用情報' => 'recruit',
        '求人' => 'recruit',
        '施工事例' => 'works',
        '制作実績' => 'works',
        '実績' => 'works',
        'ギャラリー' => 'gallery',
        '作品' => 'gallery',
        'お知らせ' => 'news',
        'ブログ' => 'blog',
        'ご予約について' => 'reservation',
        '予約について' => 'reservation',
        'ご予約' => 'reservation',
        '予約' => 'reservation',
        '代表挨拶' => 'message',
        'ご挨拶' => 'message',
        'ごあいさつ' => 'message',
        '沿革' => 'history',
        '商品' => 'products',
        'トリミング' => 'trimming',
    ];

    /**
     * 題から URL の下書きを作る。読みは `TitleReading`（IPADIC の形態素解析・UX 一周 19 周目）。
     * 何も読めなければ空を返し、呼ぶ側が土台へ倒す（漢字の題なら URL を求める）。
     */
    public static function draft(string $title): string
    {
        return TitleReading::slug($title);
    }
}
