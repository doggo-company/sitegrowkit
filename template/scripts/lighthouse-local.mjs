#!/usr/bin/env node
// 作成したサイト自身のURLと公開ページを調べ、同じ基準でPC/スマホを測定する。
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const project = resolve(fileURLToPath(new URL('..', import.meta.url)));
const require = createRequire(import.meta.url);
const maximumPages = 12;

function appUrl() {
    let value = process.env.APP_URL;
    if (!value && existsSync(join(project, '.env'))) {
        // 他の設定や資格情報をログへ出さず、APP_URLの1行だけを使用する。
        const match = readFileSync(join(project, '.env'), 'utf8').match(/^APP_URL\s*=\s*(.*)$/m);
        value = match?.[1]?.trim().replace(/^(['"])(.*)\1$/, '$2');
    }
    if (!value) throw new Error('APP_URL を指定してください。例: APP_URL=http://localhost:8181 pnpm lighthouse');
    const url = new URL(value);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) {
        throw new Error('APP_URL は認証情報・検索条件のない http(s) URL を指定してください');
    }
    if (!url.pathname.endsWith('/')) url.pathname += '/';
    return url;
}

async function get(url) {
    // URL変更や認証ページへの転送を成功として測定しない。
    return fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(10000) });
}

function xmlText(value) {
    return value.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'");
}

async function targets(base) {
    let candidates;
    if (process.env.LHCI_PATHS) {
        candidates = process.env.LHCI_PATHS.split(',').map((path) => new URL(path.trim().replace(/^\//, ''), base).href);
    } else {
        const sitemap = await get(new URL('sitemap.xml', base));
        if (!sitemap.ok) throw new Error(`sitemap.xml が HTTP ${sitemap.status} でした。対象を明示する場合は LHCI_PATHS=/,/about のように指定してください`);
        const xml = await sitemap.text();
        if (xml.length > 1024 * 1024 || !xml.includes('<urlset')) throw new Error('サイトマップを読み取れません。LHCI_PATHS で測定対象を指定してください');
        candidates = [base.href, ...Array.from(xml.matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/g), (match) => xmlText(match[1]))];
    }
    const unique = [...new Set(candidates.map((candidate) => new URL(candidate).href))];
    for (const candidate of unique) {
        const url = new URL(candidate);
        if (url.origin !== base.origin || !url.pathname.startsWith(base.pathname) || url.username || url.password || url.search || url.hash) {
            throw new Error('サイトマップまたは LHCI_PATHS に、APP_URL の範囲外のURLや検索条件が含まれています');
        }
    }
    if (process.env.LHCI_PATHS && unique.length > maximumPages) throw new Error(`LHCI_PATHS は ${maximumPages} ページ以内にしてください`);
    const selected = unique.slice(0, maximumPages);
    if (unique.length > selected.length) console.log(`サイトマップ ${unique.length} 件中、先頭 ${selected.length} 件を測定します。全ページの合格とは扱わないでください。`);
    for (const url of selected) {
        const response = await get(url);
        const type = response.headers.get('content-type') ?? '';
        await response.body?.cancel();
        if (response.status !== 200 || !type.includes('text/html')) throw new Error(`測定対象 ${url} が公開HTMLを返しません（HTTP ${response.status}）`);
    }
    return selected;
}

async function main() {
    const urls = await targets(appUrl());
    console.log('測定対象:\n' + urls.map((url) => `  ${url}`).join('\n'));
    if (process.argv.includes('--list')) return;
    const directory = mkdtempSync(join(tmpdir(), 'agentcms-lighthouse-'));
    try {
        for (const [label, filename] of [['mobile', 'lighthouserc.json'], ['desktop', 'lighthouserc.desktop.json']]) {
            const config = JSON.parse(readFileSync(join(project, filename), 'utf8'));
            // 既存の測定回数・スコア基準・予算を維持し、対象URLだけを実サイトへ合わせる。
            config.ci.collect.url = urls;
            config.ci.upload = { target: 'filesystem', outputDir: join(project, '.lighthouseci', label) };
            const configPath = join(directory, `${label}.json`);
            writeFileSync(configPath, JSON.stringify(config));
            console.log(`Lighthouse ${label}: ${config.ci.collect.numberOfRuns} 回ずつ測定`);
            const cli = require.resolve('@lhci/cli/src/cli.js');
            // npxの自動取得を使わず、lockfileで固定したローカルCLIを両OSで起動する。
            const result = spawnSync(process.execPath, [cli, 'autorun', `--config=${configPath}`], { cwd: project, stdio: 'inherit' });
            if (result.error || result.status !== 0) throw new Error(`${label} の品質基準を通過しませんでした。上の実測結果を確認してください`);
        }
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}

main().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
