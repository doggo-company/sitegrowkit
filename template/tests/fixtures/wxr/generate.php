<?php

declare(strict_types=1);

/**
 * 実データ規模の WXR を作る（Phase 9.14 / STAGE 27F）。
 *
 * ## 生成物は repo に入れない
 *
 * 数千記事の XML は数十 MB になる。**入れると diff が読めなくなり、
 * 中身を変えるたびに履歴が膨らむ。** 入れるのは作り方だけで、
 * 同じ引数からは同じものが出る（乱数を使わない）。
 *
 * ## 十年分の形に寄せる
 *
 * コメントは記事より 1 桁多く、その 1/4 が返信である。分類は親子 2 段。
 * 添付は本文から URL で参照される。**桁の関係が実物と違うと、
 * 測っても意味が変わる** — 例えばコメントが記事より少ない fixture では、
 * 台帳の照会回数が実際より軽く出る。
 *
 * ```bash
 * php tests/fixtures/wxr/generate.php /tmp/scale.xml 3000 12000 200 500
 * ```
 */
$out = $argv[1] ?? sys_get_temp_dir().'/wxr-scale.xml';
$posts = (int) ($argv[2] ?? 3000);
$comments = (int) ($argv[3] ?? 12000);
$terms = (int) ($argv[4] ?? 200);
$attachments = (int) ($argv[5] ?? 500);

$handle = fopen($out, 'w');

if ($handle === false) {
    fwrite(STDERR, "書き出せません: {$out}\n");

    exit(1);
}

$w = static function (string $chunk) use ($handle): void {
    fwrite($handle, $chunk);
};

$w('<?xml version="1.0" encoding="UTF-8"?>'
    .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
    .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
    .' xmlns:wp="http://wordpress.org/export/1.2/">'
    .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>');

// ── 分類項目（親子 2 段）──────────────────────────────────
// 先頭の 1/10 を親にし、残りをその子にする
$parents = max(1, intdiv($terms, 10));

for ($i = 1; $i <= $terms; $i++) {
    $isParent = $i <= $parents;
    $parentSlug = $isParent ? '' : 'oya-'.(($i % $parents) + 1);

    $w('<wp:term>'
        ."<wp:term_id>{$i}</wp:term_id>"
        .'<wp:term_taxonomy>news_category</wp:term_taxonomy>'
        .'<wp:term_slug>'.($isParent ? 'oya-'.$i : 'ko-'.$i).'</wp:term_slug>'
        .'<wp:term_name><![CDATA['.($isParent ? '親分類 ' : '子分類 ').$i.']]></wp:term_name>'
        ."<wp:term_parent>{$parentSlug}</wp:term_parent>"
        .'</wp:term>');
}

// ── 添付 ────────────────────────────────────────────────
// 記事より先に置かない。**本編より後ろに並ぶのが WordPress の普通**で、
// 2 周方式（§C.6）がそれを前提にしていることを、fixture の側でも守る
$attachmentIds = [];

for ($i = 1; $i <= $attachments; $i++) {
    $attachmentIds[] = 900000 + $i;
}

// ── 記事 ────────────────────────────────────────────────
// コメントは記事へ均等に配る。1/4 を返信にする
$perPost = $posts > 0 ? intdiv($comments, $posts) : 0;
$commentId = 0;

for ($p = 1; $p <= $posts; $p++) {
    $termId = (($p - 1) % $terms) + 1;
    $termSlug = $termId <= $parents ? 'oya-'.$termId : 'ko-'.$termId;

    // 添付を本文から URL で参照する（置換の対象になる）
    $attachment = $attachmentIds === [] ? null : $attachmentIds[($p - 1) % count($attachmentIds)];
    $img = $attachment === null
        ? ''
        : '<img src="https://example.invalid/wp/uploads/'.$attachment.'.png">';

    $w("<item><title>記事 {$p}</title><wp:post_id>{$p}</wp:post_id>"
        .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
        ."<wp:post_name>kiji-{$p}</wp:post_name>"
        ."<content:encoded><![CDATA[本文 {$p} — 十年分の移行を模した本文です。{$img}]]></content:encoded>"
        ."<category domain=\"category\" nicename=\"{$termSlug}\"><![CDATA[{$termSlug}]]></category>"
        // 1/5 は閉じている（受付設定の行を持つ束と持たない束を混ぜる）
        .($p % 5 === 0 ? '<wp:comment_status>closed</wp:comment_status>' : ''));

    $roots = [];

    for ($c = 0; $c < $perPost && $commentId < $comments; $c++) {
        $commentId++;
        // 4 件に 1 件を返信にする。親はこの記事の直前の 1 段目
        $isReply = $c % 4 === 3 && $roots !== [];
        $parent = $isReply ? $roots[count($roots) - 1] : 0;

        if (! $isReply) {
            $roots[] = $commentId;
        }

        $w('<wp:comment>'
            ."<wp:comment_id>{$commentId}</wp:comment_id>"
            .'<wp:comment_author><![CDATA[書いた人]]></wp:comment_author>'
            .'<wp:comment_author_email>kaita@example.invalid</wp:comment_author_email>'
            .'<wp:comment_author_url></wp:comment_author_url>'
            .'<wp:comment_author_IP>198.51.100.1</wp:comment_author_IP>'
            .'<wp:comment_date>2016-01-01 09:00:00</wp:comment_date>'
            .'<wp:comment_date_gmt>2016-01-01 00:00:00</wp:comment_date_gmt>'
            ."<wp:comment_content><![CDATA[コメント {$commentId}]]></wp:comment_content>"
            .'<wp:comment_approved>1</wp:comment_approved>'
            ."<wp:comment_parent>{$parent}</wp:comment_parent>"
            .'</wp:comment>');
    }

    $w('</item>');
}

foreach ($attachmentIds as $id) {
    $w("<item><title>{$id}.png</title><wp:post_id>{$id}</wp:post_id>"
        .'<wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status>'
        ."<wp:attachment_url>https://example.invalid/wp/uploads/{$id}.png</wp:attachment_url>"
        .'</item>');
}

$w('</channel></rss>');
fclose($handle);

printf(
    "%s を書きました（記事 %d / コメント %d / 分類 %d / 添付 %d / %.1f MB）\n",
    $out,
    $posts,
    $commentId,
    $terms,
    $attachments,
    filesize($out) / 1024 / 1024,
);
