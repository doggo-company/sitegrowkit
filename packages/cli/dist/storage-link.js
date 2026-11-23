/**
 * `public/storage` が本当に通っているか（WP 一周 6 周目 2026-09-03）。
 *
 * ## なぜ要るか
 *
 * `agentcms dev` は `php artisan storage:link` を実行していたが、
 * **この 1 行だけ結果を見ていなかった**（`runCapture(...)` の戻り値を捨てていた）。
 * 他の artisan は全部 exit code を見て、失敗したら題名付きで止まる。
 *
 * 張られていないと、**アップロードした画像が全部 403** になる。
 * 管理画面のメディア一覧に壊れた画像が並ぶだけで、**画面は何も言わない**。
 * 6 周目の確認環境が実際にその状態だった。
 *
 * ## exit code だけでは足りない
 *
 * `storage:link` は、`public/storage` が既に在るとき
 * **`ERROR  The [public/storage] link already exists.` と出しながら exit 0 を返す**（実測）。
 * つまり成功も失敗も 0 で返りうる。**通っていることを別に確かめる。**
 *
 * ## 「在る」ではなく「通っている」を見る
 *
 * `[ -e public/storage ]` だけだと、**別の場所を指した symlink** や
 * 中身の無いディレクトリでも通ってしまう。
 * 印を `storage/app/public` に置き、`public/storage` 越しに読めるかで確かめる。
 */
export function storageLinkProbeScript() {
    const marker = ".agentcms-link-probe";
    const source = `storage/app/public/${marker}`;
    const through = `public/storage/${marker}`;
    return (`{ [ -d 'storage/app/public' ] && touch '${source}' ` +
        `&& [ -e '${through}' ] && rm -f '${source}'; } ` +
        `|| { echo 'STORAGE LINK BROKEN'; rm -f '${source}'; exit 1; }`);
}
