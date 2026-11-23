import { importPage, readFrontPageId, setFrontPage } from "./page-bridge.js";
export const DEFAULT_PAGE_BRIDGE = {
    importPage,
    readFrontPageId,
    setFrontPage,
};
/** dry-run で見せる行（**1 バイトも書かない**。書くときと同じ判断材料を出す） */
export function describeImportedPages(pages, previous) {
    const lines = [];
    for (const page of pages) {
        const prev = previous.find((p) => p.slug === page.slug);
        const front = page.front ? "、トップページに指定" : "";
        const publish = page.publish ? "公開" : "下書き";
        lines.push(`would create page: ${page.slug}「${page.title}」（ブロック ${page.blocks.length} 件、${publish}${front}）— 既に在れば触りません`);
        if (prev !== undefined) {
            lines.push(`  前回の記録: ${prev.action}${prev.id === null ? "" : ` (id ${prev.id})`}${prev.published ? "・公開済み" : "・未公開"}`);
        }
        for (const name of page.passthrough) {
            lines.push(`  原本のまま置く（画面からは編集できません）: ${name}`);
        }
    }
    return lines;
}
export async function applyImportedPages(pages, previous, options, bridge = DEFAULT_PAGE_BRIDGE) {
    const outcome = { records: [], lines: [], ok: true };
    for (const page of pages) {
        const prev = previous.find((p) => p.slug === page.slug);
        const label = `ページ [${page.slug}]`;
        const result = await bridge.importPage(page, options);
        // 前回までにこの import が作ったページか（古い記録に `ours` が無ければ created だけを自分のものと読む）
        const prevOurs = prev === undefined ? false : (prev.ours ?? prev.action === "created");
        const record = {
            slug: page.slug,
            title: page.title,
            source: page.source,
            front: page.front,
            action: result.kind,
            id: result.kind === "created" || result.kind === "kept" ? result.id : null,
            published: result.kind === "created" || result.kind === "kept"
                ? result.published
                : false,
            ours: result.kind === "created" ? true : prevOurs,
            front_set: false,
        };
        outcome.records.push(record);
        if (result.kind === "failed") {
            record.message = result.message;
            outcome.ok = false;
            outcome.lines.push({
                level: "fail",
                text: `${label} を作れませんでした: ${result.message}。` +
                    "作成は 1 トランザクションなので、半端なページは残っていません。原因を直してから再実行してください",
            });
            continue;
        }
        if (result.kind === "unknown") {
            record.message = result.message;
            outcome.ok = false;
            outcome.lines.push({
                level: "fail",
                text: `${label} が作られたか確認できませんでした: ${result.message}。` +
                    "CMS の状態を確認してから再実行してください（既に在れば触りません）",
            });
            continue;
        }
        if (result.kind === "created") {
            outcome.lines.push({
                level: "info",
                text: `page: ${label}「${page.title}」を作成（ブロック ${page.blocks.length} 件${result.published ? "、公開" : "、下書き"}）`,
            });
            if (page.publish && !result.published) {
                // 公開を頼んだのに公開されていない、は成功ではない
                outcome.ok = false;
                outcome.lines.push({
                    level: "fail",
                    text: `${label} は作成されましたが公開されていません（CMS の応答: published=false）`,
                });
                continue;
            }
            if (page.front && result.published) {
                await frontStep(record, label, true, options, bridge, outcome);
            }
            continue;
        }
        /*
         * ---- kept: 既に在る。**誰のものかは記録（`ours`）で決め、公開状態では決めない** ----
         *
         * `import-page` は作成 → ブロック → 公開を 1 トランザクションで行うので、
         * この import が作ったページが未公開なのは**運用者が取り消したとき**だけである。
         * 自分のものと確かめられない未公開ページ（同じ URL の別のページ・ゴミ箱の中のページ・
         * 前回の結果を確かめられなかったページ）は**達成ではない**。
         * 記録は `ours: false` のまま残すので、**何度 apply しても警告だけの成功に劣化しない**
         * （Codex 指摘 ③）。
         */
        if (!result.published) {
            if (record.ours) {
                outcome.lines.push({
                    level: "warn",
                    text: `${label} は既に在りますが公開が取り消されています。運用者の操作として触っていません（トップページにも反映していません）`,
                });
                continue;
            }
            outcome.ok = false;
            outcome.lines.push({
                level: "fail",
                text: prev?.action === "unknown"
                    ? `${label} は、前回の apply で作られたか確かめられなかったページが未公開のまま在ります。` +
                        "管理画面で中身を確かめ、この import のものなら公開してください（公開されれば次の apply がトップページに指定します）"
                    : `${label} と同じ URL のページ（ゴミ箱の中を含む）が既に在り、公開されていません。` +
                        "この import のものではないため触っていません。そのページを復元して公開するか、URL を空けてから再実行してください",
            });
            continue;
        }
        outcome.lines.push({
            level: "info",
            text: `page: ${label} は既に在るので触っていません（管理画面での編集を上書きしません）`,
        });
        if (page.front) {
            await frontStep(record, label, record.ours, options, bridge, outcome);
        }
    }
    return outcome;
}
/**
 * `/` がこのページを出すようにする。
 *
 * @param ours この import が作ったと記録で確かめられているか。**確かめられないものは立てない**
 */
async function frontStep(record, label, ours, options, bridge, outcome) {
    const pageId = record.id;
    if (pageId === null)
        return;
    const current = await bridge.readFrontPageId(options);
    if (current.kind === "unknown") {
        // 読めない = 未設定ではない。差し替えない。ただし **`/` に出ていないなら達成ではない**
        outcome.ok = false;
        outcome.lines.push({
            level: "fail",
            text: `トップページの設定を確認できないため変更していません: ${current.message}（${label} は /${record.slug} にだけ出ています）`,
        });
        return;
    }
    if (current.id === pageId) {
        outcome.lines.push({
            level: "info",
            text: `front: トップページは既に ${label} を指しています（/ がページを出します）`,
        });
        return;
    }
    if (current.id !== null) {
        outcome.lines.push({
            level: "warn",
            text: `front: トップページには別のページ (id ${current.id}) が指定されているため変更していません（${label} は /${record.slug} に出ます）`,
        });
        return;
    }
    if (!ours) {
        outcome.lines.push({
            level: "warn",
            text: `front: トップページが未設定ですが、${label} はこの import が作ったと確かめられないため指定していません。` +
                `トップに出すには 管理画面 > サイト設定、または \`agentcms:site-setting front_page_id ${pageId}\``,
        });
        return;
    }
    const set = await bridge.setFrontPage(pageId, options);
    if (set.kind === "failed") {
        outcome.ok = false;
        outcome.lines.push({
            level: "fail",
            text: `front: トップページに ${label} を指定できませんでした: ${set.message}（/ は静的なトップのままです）`,
        });
        return;
    }
    record.front_set = true;
    outcome.lines.push({
        level: "info",
        text: `front: トップページに ${label} (id ${pageId}) を指定 — / がページを出し、管理画面の編集が反映されます`,
    });
}
