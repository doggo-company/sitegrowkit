import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';

// 公開検索とページ送りを、非公開記事の漏れとスマホ操作まで検証する。
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

test('公開記事を検索して次ページへ進める。下書きと未来の記事は含まない', async ({
    page,
}) => {
    const stamp = Date.now();
    const label = `検索検証${stamp}`;
    // 専用QAのpostに、公開13件と非公開2件を作る。実案件には接続しない。
    const code = `for($n=1;$n<=15;$n++){ \\Illuminate\\Support\\Facades\\DB::table('post')->insert(['title'=>'${label} '.$n,'slug'=>'search-${stamp}-'.$n,'body'=>'<p>本文</p>','status'=>$n===14?'draft':'published','published_at'=>$n===15?now()->addDay():now()->subDay(),'lock_version'=>0,'created_at'=>now(),'updated_at'=>now()]); }`;
    execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'tinker',
            '--execute',
            code,
        ],
        { cwd: composeCwd },
    );
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/blog');
    await page.locator('#content-search').fill(label);
    await page.getByRole('button', { name: '探す', exact: true }).click();
    await expect(
        page
            .locator('.news-items')
            .getByRole('link', { name: `${label} 13`, exact: true }),
    ).toBeVisible();
    await expect(page.locator('.news-items')).not.toContainText(`${label} 14`);
    await expect(page.locator('.news-items')).not.toContainText(`${label} 15`);
    await page.getByRole('link', { name: '次へ', exact: true }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page.locator('#content-search')).toHaveValue(label);
    await expect(
        page
            .locator('.news-items')
            .getByRole('link', { name: `${label} 1`, exact: true }),
    ).toBeVisible();
    await expect(page.locator('.news-items')).not.toContainText(`${label} 13`);
    await page.getByRole('link', { name: '前へ', exact: true }).click();
    await expect(
        page
            .locator('.news-items')
            .getByRole('link', { name: `${label} 13`, exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
});
