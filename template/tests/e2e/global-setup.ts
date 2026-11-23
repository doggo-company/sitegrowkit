import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// E2E は毎回同じ DB 状態から始める（テスト間・実行間のコンテンツ汚染で
// Template Import の視覚 parity 検証が壊れるのを防ぐ）。
// 内容: migrate:fresh → 管理者 + Template import コンテンツ + 問い合わせ 1 件。
export default function globalSetup(): void {
    const cwd = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
    const artisan = (cmd: string): void => {
        execSync(`docker compose exec -T app php artisan ${cmd}`, {
            cwd,
            stdio: 'inherit',
        });
    };

    artisan('migrate:fresh --force');
    artisan("db:seed --class='Database\\Seeders\\AdminUserSeeder' --force");
    // 分類定義を DB へ反映する（runtime の暗黙 write は禁止なので、
    // setup の明示ステップとしてここで 1 度だけ実行する）。
    // 項目そのものは各 spec が自分で作るので、ここでは taxonomy だけを用意する。
    artisan('agentcms:taxonomy-sync');
    artisan(
        "db:seed --class='Database\\Seeders\\TemplateContentSeeder' --force",
    );
    // 管理画面「問い合わせ一覧」テスト用の初期 submission（status は DB default = received）
    artisan(
        `tinker --execute="\\Modules\\Contact\\Models\\ContactSubmission::query()->forceCreate(['name' => 'E2E Seed', 'email' => 'seed@example.test', 'subject' => 'other', 'message' => 'E2E 初期データ', 'ip' => '127.0.0.1']);"`,
    );
}
