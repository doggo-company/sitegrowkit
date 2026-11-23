import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';

// 公開検索のために合成データを作る。ローカルの隔離プロジェクトだけを対象にする。
function isolatedTarget(): { appUrl: string; composeCwd: string } {
    const appUrl = process.env.APP_URL;
    const composeCwd = process.env.AGENTCMS_COMPOSE_CWD;

    if (!appUrl || !composeCwd) {
        throw new Error(
            'APP_URL と AGENTCMS_COMPOSE_CWD を明示してください（使い捨てプロジェクト専用の spec です）',
        );
    }

    let parsed: URL;

    try {
        parsed = new URL(appUrl);
    } catch {
        throw new Error(`APP_URL を URL として読めません: ${appUrl}`);
    }

    if (parsed.protocol !== 'http:') {
        throw new Error(
            `手元の使い捨て以外へは向けられません（http のみ）: ${appUrl}`,
        );
    }

    if (!['localhost', '127.0.0.1', '[::1]'].includes(parsed.hostname)) {
        throw new Error(`手元のホストではありません: ${parsed.hostname}`);
    }

    if (parsed.port === '' || parsed.port === '8180') {
        throw new Error(`使い捨て専用の port を明示してください: ${appUrl}`);
    }

    if (resolve(composeCwd) === resolve(process.cwd())) {
        throw new Error(`repo 自身を対象にできません: ${composeCwd}`);
    }

    for (const marker of [
        '.agentcms/local/admin-credentials.json',
        'docker-compose.yml',
        '.env',
    ]) {
        if (!existsSync(join(composeCwd, marker))) {
            throw new Error(
                `生成プロジェクトに見えません（${marker} が無い）: ${composeCwd}`,
            );
        }
    }

    return { appUrl, composeCwd };
}

const { composeCwd } = isolatedTarget();


for (const width of [1280, 390]) {
    test(`公開検索 ${width}px: 記事とページを探し、未公開を除外する`, async ({ page }, testInfo) => {
        const fixture = execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php'], { cwd: composeCwd, encoding: 'utf8', input: `<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$term = '検索試験'.bin2hex(random_bytes(5));
$model = app(AgentCms\\Core\\Application\\Content\\ContentTypeResolver::class)->newModel('post');
for ($i = 0; $i < 15; $i++) {
    $model->newQuery()->create(['title' => $term.'記事'.$i, 'slug' => 'search-'.bin2hex(random_bytes(8)), 'body' => '<p>'.$term.'本文</p>', 'status' => $i === 13 ? 'draft' : 'published', 'published_at' => $i === 14 ? now()->addDay() : now()->subDay()]);
}
$page = AgentCms\\Core\\Pages\\Page::query()->create(['title' => $term.'ページ', 'slug' => 'search-page-'.bin2hex(random_bytes(8)), 'managed_by' => 'document', 'status' => 'published', 'published_at' => now()->subDay()]);
$version = AgentCms\\Core\\Pages\\PageVersion::query()->create(['page_id' => $page->id, 'revision' => 1, 'document' => ['version' => 1, 'blocks' => []]]);
$page->update(['published_version_id' => $version->id]);
echo json_encode(['term' => $term, 'pageSlug' => $page->slug], JSON_THROW_ON_ERROR);
` });
        const data = JSON.parse(fixture) as { term: string; pageSlug: string };
        await page.setViewportSize({ width, height: 850 });
        await page.goto('/_search');
        await page.getByRole('searchbox', { name: 'キーワード' }).fill(data.term);
        await page.getByRole('button', { name: '検索', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('14件');
        await expect(page.getByRole('article')).toHaveCount(12);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: testInfo.outputPath(`site-search-${width}.png`), fullPage: true });
        await expect(page.getByRole('main')).not.toContainText(data.term + '記事13');
        await expect(page.getByRole('main')).not.toContainText(data.term + '記事14');
        await page.getByRole('link', { name: '次へ', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('14件');
        await expect(page.getByRole('article')).toHaveCount(2);
        await page.getByRole('link', { name: data.term + 'ページ', exact: true }).click();
        await expect(page).toHaveURL(new RegExp('/' + data.pageSlug + '$'));
        await expect(page).toHaveTitle(new RegExp(data.term));
        await page.getByRole('link', { name: 'サイト内検索', exact: true }).click();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    });
}
