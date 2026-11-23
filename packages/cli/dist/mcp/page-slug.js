import { readSiteName } from "../wizard/reading.js";
/**
 * MCP の page_create で slug が空のとき、題の読みから付ける（UX 一周 18 周目 A1）。
 *
 * 以前は空のまま PHP へ渡り、漢字の題は黙って `/page-2` になっていた。読みは管理画面と setup と
 * **同じ関数**（template の `reading-slug.mjs`・kuromoji）。同じ URL が既にあれば `-2`, `-3` … を試す。
 * 読めなかったときは空のまま渡し、PHP が「URL を入れてください」と返す（黙って page にしない）。
 */
const MAX_TRIES = 20;
export async function createPageWithReading(args, invoke, read = readSiteName) {
    const slug = typeof args.slug === "string" ? args.slug.trim() : "";
    const title = typeof args.title === "string" ? args.title : "";
    if (slug !== "" || title.trim() === "")
        return invoke(args);
    const base = await read(title).catch(() => "");
    if (base === "")
        return invoke(args);
    let last = null;
    for (let n = 1; n <= MAX_TRIES; n += 1) {
        const candidate = n === 1 ? base : `${base}-${n}`;
        last = await invoke({ ...args, slug: candidate });
        // 使われている URL だったときだけ次の番号へ（ほかの誤りはそのまま返す）
        if (!(last.status === "validation_error" && last.errors?.slug))
            return last;
    }
    return last;
}
