import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

// 手元の仮サイトへCLIを向け、対象URLの選定と失敗の扱いを確認する。
async function fixture(run) {
    let contents;
    const seen = [];
    const server = createServer((request, response) => {
        seen.push(request.url);
        if (request.url === '/sitemap.xml') {
            response.setHeader('Content-Type', 'application/xml');
            response.end(contents());
        } else if (request.url === '/missing') {
            response.writeHead(404); response.end('missing');
        } else if (request.url === '/redirect') {
            response.writeHead(302, { Location: '/login' }); response.end();
        } else {
            response.setHeader('Content-Type', 'text/html');
            response.end('<!doctype html><title>公開ページ</title>');
        }
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    const base = `http://127.0.0.1:${server.address().port}`;
    contents = () => `<urlset><url><loc>${base}</loc></url><url><loc>${base}/about</loc></url></urlset>`;
    async function cli(extra = {}) {
        const child = spawn(process.execPath, [fileURLToPath(new URL('./lighthouse-local.mjs', import.meta.url)), '--list'], {
            env: { ...process.env, APP_URL: base, LHCI_PATHS: '', ...extra }, stdio: ['ignore', 'pipe', 'pipe'],
        });
        let output = '';
        child.stdout.on('data', (chunk) => { output += chunk; });
        child.stderr.on('data', (chunk) => { output += chunk; });
        const [code] = await once(child, 'close');
        return { code, output };
    }
    try { await run({ base, cli, seen, sitemap: (getContents) => { contents = getContents; } }); }
    finally { server.closeAllConnections(); server.close(); await once(server, 'close'); }
}

test('ポートと実サイトのページを使い、ルートの重複を除く', async () => fixture(async ({ cli, seen }) => {
    const result = await cli();
    assert.equal(result.code, 0, result.output);
    assert.deepEqual(seen, ['/sitemap.xml', '/', '/about']);
    assert.doesNotMatch(result.output, /8180|imported-work/);
}));

test('明示したページが404でも転送でも合格にしない', async () => fixture(async ({ cli }) => {
    for (const path of ['/missing', '/redirect']) {
        const result = await cli({ LHCI_PATHS: `/,${path}` });
        assert.equal(result.code, 1, result.output);
        assert.match(result.output, /公開HTMLを返しません/);
    }
}));

test('サイトマップ内の別オリジンへはアクセスしない', async () => fixture(async ({ cli, seen, sitemap }) => {
    sitemap(() => '<urlset><url><loc>http://127.0.0.1:1/private</loc></url></urlset>');
    const result = await cli();
    assert.equal(result.code, 1);
    assert.match(result.output, /範囲外/);
    assert.deepEqual(seen, ['/sitemap.xml']);
}));

test('対象数を制限した場合は全件合格ではないと明示する', async () => fixture(async ({ cli, base, sitemap, seen }) => {
    sitemap(() => `<urlset>${Array.from({ length: 15 }, (_, index) => `<url><loc>${base}/page-${index}</loc></url>`).join('')}</urlset>`);
    const result = await cli();
    assert.equal(result.code, 0, result.output);
    assert.match(result.output, /16 件中、先頭 12 件/);
    assert.equal(seen.length, 13);
}));

test('認証情報を含むURLは拒否し値を出力しない', async () => fixture(async ({ cli }) => {
    const result = await cli({ APP_URL: 'http://sample:do-not-print@localhost:1234/' });
    assert.equal(result.code, 1);
    assert.doesNotMatch(result.output, /do-not-print/);
}));
