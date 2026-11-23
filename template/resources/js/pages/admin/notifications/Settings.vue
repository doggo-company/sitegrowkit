<script setup lang="ts">
// 通知設定。権限要求は「通知を有効にする」ボタン起点のみ（PERMISSION UX）。
// Push 非対応環境でも管理画面は通常どおり使える — 案内を出すだけで機能は落とさない。
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Bell, BellOff, Download } from '@lucide/vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { useAdminPwa } from '@/composables/useAdminPwa';
import { usePushNotifications } from '@/composables/usePushNotifications';

interface PreferenceItem {
    type: string;
    label: string;
    enabled: boolean;
}

const props = defineProps<{
    groups: { group: string; items: PreferenceItem[] }[];
    vapidPublicKey: string;
    pushConfigured: boolean;
}>();

const { supported, permission, subscribed, busy, error, enable, disable } =
    usePushNotifications(props.vapidPublicKey);

const { installPromptEvent, isStandalone, isIos, promptInstall } =
    useAdminPwa();

const form = useForm({
    preferences: props.groups.flatMap((group) =>
        group.items.map((item) => ({ type: item.type, enabled: item.enabled })),
    ),
});

function submit() {
    form.put('/admin/notifications/preferences', { preserveScroll: true });
}

// SW 診断情報（サポート・実機トラブルシュート用。秘密情報は含まない）
const swDiag = ref<string>('読み込み中…');

onMounted(async () => {
    const lines: string[] = [];

    try {
        if ('serviceWorker' in navigator) {
            const reg = await navigator.serviceWorker.getRegistration('/admin');
            lines.push(
                `controller: ${navigator.serviceWorker.controller ? 'あり' : 'なし'}`,
            );
            lines.push(
                `active: ${reg?.active?.state ?? 'なし'} / waiting: ${reg?.waiting ? 'あり' : 'なし'}`,
            );
        } else {
            lines.push('serviceWorker: 非対応');
        }

        if ('caches' in window) {
            const keys = await caches.keys();
            lines.push(`caches: ${keys.join(', ') || '(なし)'}`);
            const nav = await caches.open('agentcms-admin-nav');
            const pending = await nav.match('/__agentcms/pending-navigation');
            lines.push(
                `pending-nav: ${pending ? await pending.text() : 'なし'}`,
            );
        }

        lines.push(`standalone: ${String(isStandalone)}`);
    } catch (e) {
        lines.push(`診断エラー: ${e instanceof Error ? e.message : String(e)}`);
    }

    swDiag.value = lines.join('\n');
});

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head title="通知設定" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">通知設定</h1>

        <!-- Push 通知の有効化（この端末） -->
        <Card data-testid="push-card">
            <CardHeader>
                <CardTitle>この端末の Push 通知</CardTitle>
                <CardDescription>
                    新しいお問い合わせなどを、この端末にプッシュ通知でお知らせします。
                </CardDescription>
            </CardHeader>

            <CardContent class="flex flex-col gap-3">
                <p
                    v-if="!supported"
                    class="text-sm text-muted-foreground"
                    data-testid="push-unsupported"
                >
                    このブラウザは Push 通知に対応していません。
                    <template v-if="isIos && !isStandalone">
                        iPhone / iPad では、Safari
                        の共有メニューから「ホーム画面に追加」でインストールしたアプリ内でのみ通知を利用できます。
                    </template>
                </p>

                <p
                    v-else-if="!pushConfigured"
                    class="text-sm text-muted-foreground"
                    data-testid="push-not-configured"
                >
                    サーバーの VAPID キーが未設定のため Push
                    通知を利用できません。
                    <code class="text-xs"
                        >php artisan notifications:vapid-keys</code
                    >
                    で生成し .env へ設定してください。
                </p>

                <template v-else>
                    <p
                        v-if="permission === 'denied'"
                        class="text-sm text-destructive"
                    >
                        通知がブラウザでブロックされています。ブラウザのサイト設定から通知を許可してください。
                    </p>

                    <div v-else class="flex items-center gap-3">
                        <Button
                            v-if="!subscribed"
                            :disabled="busy"
                            data-testid="enable-push"
                            @click="enable"
                        >
                            <Bell class="mr-1 size-4" />
                            通知を有効にする
                        </Button>

                        <template v-else>
                            <span class="text-sm" data-testid="push-enabled"
                                >この端末で通知が有効です</span
                            >
                            <Button
                                variant="outline"
                                :disabled="busy"
                                data-testid="disable-push"
                                @click="disable"
                            >
                                <BellOff class="mr-1 size-4" />
                                無効にする
                            </Button>
                        </template>
                    </div>

                    <p v-if="error" class="text-sm text-destructive">
                        {{ error }}
                    </p>

                    <p
                        v-if="isIos && !isStandalone"
                        class="text-sm text-muted-foreground"
                    >
                        iPhone / iPad では、共有メニュー →
                        「ホーム画面に追加」でインストールしたアプリから通知を有効にしてください。
                    </p>
                </template>
            </CardContent>
        </Card>

        <!-- 通知種別ごとの ON/OFF -->
        <Card data-testid="preferences-card">
            <CardHeader>
                <CardTitle>受け取る通知</CardTitle>
                <CardDescription>
                    通知の種類ごとに受け取るかどうかを選べます（全端末共通）。
                </CardDescription>

                <!--
                    🔴 押せて、保存できて、「保存しました」と出るのに何も起きない、を作らない
                    （WP 一周 10 周目 2026-09-04 / 効くもの ②）。
                    上の Card にだけ書いても、ここだけ見た人には届かない。
                -->
                <p
                    v-if="!pushConfigured"
                    class="text-sm text-muted-foreground"
                    data-testid="preferences-unavailable"
                >
                    Push 通知の設定が済んでいないため、ここを変えても
                    <strong>いまは届きません</strong>。サーバーで
                    <code class="text-xs"
                        >php artisan notifications:vapid-keys</code
                    >
                    を実行し、出てきた鍵を .env の
                    <code class="text-xs">VAPID_PUBLIC_KEY</code> /
                    <code class="text-xs">VAPID_PRIVATE_KEY</code>
                    へ設定すると使えるようになります。
                </p>
            </CardHeader>

            <CardContent>
                <form class="flex flex-col gap-5" @submit.prevent="submit">
                    <div
                        v-for="group in groups"
                        :key="group.group"
                        class="flex flex-col gap-3"
                    >
                        <h3 class="text-sm font-medium text-muted-foreground">
                            {{ group.group }}
                        </h3>

                        <label
                            v-for="item in group.items"
                            :key="item.type"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="
                                    form.preferences.find(
                                        (p) => p.type === item.type,
                                    )?.enabled
                                "
                                :disabled="!pushConfigured"
                                :data-testid="`pref-${item.type}`"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') => {
                                        const target = form.preferences.find(
                                            (p) => p.type === item.type,
                                        );

                                        if (target) {
                                            target.enabled = value === true;
                                        }
                                    }
                                "
                            />
                            {{ item.label }}
                        </label>
                    </div>

                    <Button
                        type="submit"
                        class="self-start"
                        :disabled="form.processing || !pushConfigured"
                        data-testid="save-preferences"
                    >
                        保存
                    </Button>
                </form>
            </CardContent>
        </Card>

        <!-- インストール案内（過剰な誘導はしない — このページ内のみ） -->
        <Card data-testid="install-card">
            <CardHeader>
                <CardTitle>アプリとしてインストール</CardTitle>
                <CardDescription>
                    管理画面をホーム画面 /
                    デスクトップに追加すると、アプリとして起動できます。
                </CardDescription>
            </CardHeader>

            <CardContent>
                <p v-if="isStandalone" class="text-sm text-muted-foreground">
                    インストール済みのアプリとして表示中です。
                </p>

                <Button
                    v-else-if="installPromptEvent"
                    variant="outline"
                    data-testid="install-pwa"
                    @click="promptInstall"
                >
                    <Download class="mr-1 size-4" />
                    インストール
                </Button>

                <p v-else-if="isIos" class="text-sm text-muted-foreground">
                    Safari の共有ボタン
                    →「ホーム画面に追加」からインストールできます。
                </p>

                <p v-else class="text-sm text-muted-foreground">
                    お使いのブラウザのメニューから「インストール」または「アプリをインストール」を選択してください。
                </p>
            </CardContent>
        </Card>

        <!-- SW 診断（実機トラブルシュート用） -->
        <Card data-testid="sw-diagnostics">
            <CardHeader>
                <CardTitle>診断情報</CardTitle>
                <CardDescription
                    >Push / PWA
                    の不具合調査に使う技術情報です。</CardDescription
                >
            </CardHeader>
            <CardContent>
                <pre
                    class="text-xs whitespace-pre-wrap text-muted-foreground"
                    >{{ swDiag }}</pre>
            </CardContent>
        </Card>
    </div>
</template>
