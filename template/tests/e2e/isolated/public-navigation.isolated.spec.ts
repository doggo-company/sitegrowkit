import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';

// 同梱公開テーマの階層メニューを、実際のHTTPとキーボード・タッチで検証する。
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

test('公開テーマの階層メニューをEnterで開きEscapeで閉じ、スマホでも子リンクへ進める', async ({
    page,
}) => {
    const label = `メニュー検証${Date.now()}`;
    // この試験が作る親子だけ追加する。既存サイトへはisolatedTargetの検査で到達できない。
    const code = `foreach(['header','footer'] as $key){ $menu=\\Illuminate\\Support\\Facades\\DB::table('menus')->where('key',$key)->value('id'); if(!$menu)throw new \\RuntimeException('QA menu missing'); $parent=\\Illuminate\\Support\\Facades\\DB::table('menu_items')->insertGetId(['menu_id'=>$menu,'title'=>'${label}','link_type'=>'custom','link_url'=>'/','sort_order'=>99,'target'=>'_self','created_at'=>now(),'updated_at'=>now()]); \\Illuminate\\Support\\Facades\\DB::table('menu_items')->insert(['menu_id'=>$menu,'parent_id'=>$parent,'title'=>'${label}の子','link_type'=>'custom','link_url'=>'/about','sort_order'=>0,'target'=>'_self','created_at'=>now(),'updated_at'=>now()]); }`;
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

    await page.goto('/');
    const toggle = page.getByRole('button', { name: `${label}のサブメニュー` });
    const child = page
        .locator('#global-nav')
        .getByRole('link', { name: `${label}の子`, exact: true });
    await expect(child).toBeHidden();
    await toggle.focus();
    await toggle.press('Enter');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(child).toBeVisible();
    await child.focus();
    await child.press('Escape');
    await expect(child).toBeHidden();
    await expect(toggle).toBeFocused();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.click();
    await expect(child).toBeVisible();
    await toggle.click();
    await expect(child).toBeHidden();
    await expect(
        page
            .locator('footer')
            .getByRole('link', { name: `${label}の子`, exact: true }),
    ).toBeVisible();

    await page.setViewportSize({ width: 375, height: 812 });
    await page.locator('.nav-toggle').click();
    await expect(child).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await child.click();
    await expect(page).toHaveURL(/\/about$/);
});
