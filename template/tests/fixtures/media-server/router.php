<?php

declare(strict_types=1);

/**
 * 大きさの守りを**本物の curl で**当てるための応答（Phase 9.14 / キュー3）。
 *
 * `Http::fake()` は curl を通らないので、`CURLOPT_MAXFILESIZE` も進捗 callback も
 * 一度も呼ばれない。**効くかどうかは、本物を通さないと分からない。**
 *
 * 3 つの経路を出し分ける:
 *
 * - `/declared`  … `Content-Length` を宣言した巨大な応答（`CURLOPT_MAXFILESIZE` の領分）
 * - `/chunked`   … 宣言しない巨大な応答（**進捗 callback でしか止まらない**）
 * - `/small`     … 上限内の応答（**止めてはいけない**側）
 *
 * 巨大といっても、こちらのディスクとメモリを使わないように**その場で作って流す**。
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$chunk = str_repeat('x', 64 * 1024);
$chunks = 200; // 12.5 MB — 試験の上限（1 MB）より十分大きい

header('Content-Type: image/png');

if ($path === '/small') {
    echo str_repeat('x', 1024);

    return;
}

if ($path === '/declared') {
    header('Content-Length: '.($chunks * strlen($chunk)));
}

// chunked のときは Content-Length を出さない（PHP が chunked で返す）
for ($i = 0; $i < $chunks; $i++) {
    echo $chunk;
    flush();
}
