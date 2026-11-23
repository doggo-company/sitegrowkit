import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * サーバーの時間帯（WP 一周 5 周目 2026-09-03 / 効くもの ①）。
 *
 * `HandleInertiaRequests` が `config('app.timezone')` を共有している。
 * **画面はブラウザの時計を見ない** — 出張先や海外の担当者の画面だけ
 * 公開日時がずれ、しかも本人には気づけないためである。
 *
 * 共有が届かない場面（試験の断片・古い応答）では `UTC` へ倒す。
 * `admin-datetime` は時間帯を持たない文字を動かさないので、
 * ここが既定に落ちても**保存済みの値が壊れることはない**。
 */
export function useAdminTimezone(): ComputedRef<string> {
    const page = usePage();

    return computed(() => {
        const shared = page.props.timezone;

        return typeof shared === 'string' && shared !== '' ? shared : 'UTC';
    });
}
