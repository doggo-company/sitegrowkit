<?php

declare(strict_types=1);

namespace AgentCms\Core\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Site settings の key-value ストア（settings テーブル）。
 * 編集可能な項目は content/schemas/site.json が定義する（Schema First）。
 */
final class SettingsStore
{
    private const CACHE_KEY = 'agentcms.settings';

    /** @return array<string, mixed> */
    public function all(): array
    {
        /** @var array<string, mixed> */
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return DB::table('settings')
                ->pluck('value', 'key')
                ->map(fn ($value) => json_decode((string) $value, true))
                ->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * 覚えている値を捨てる（WP 一周 9 周目 2026-09-04 / 効くもの ①）。
     *
     * 🔴 **DB の `settings` を直に書き換えた側が、必ずこれを呼ぶ。**
     *
     * `all()` は `rememberForever` なので、誰かが捨てるまで**永久に**残る。
     * backup からの復元は DB を丸ごと置き換えるが、`put()` を通らないので
     * ここが呼ばれず、**復元したのに古い値を出し続ける**状態になっていた。
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @param  array<string, mixed>  $values */
    public function put(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                // 1 文で「無ければ作る・在れば替える」。updateOrInsert（読んでから書く）は、同じ行を
                // 2 つの要求が同時に初めて書くと両方が INSERT を走らせ、主キー重複で 500 になっていた
                // （UX 一周 7 周目 R7-2: 見本の「非公開にする」をダブルクリック）
                DB::table('settings')->upsert(
                    [['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]],
                    ['key'],
                    ['value', 'updated_at'],
                );
            }
        });

        self::forget();
    }
}
