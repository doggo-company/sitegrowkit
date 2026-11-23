<script setup lang="ts">
/**
 * 公開前チェック（2026-08-30）。
 *
 * ## 数えて見せるだけ
 *
 * 直す場所はそれぞれ別の画面に在る。ここで直せるようにすると、
 * 同じことを 2 か所で決めることになる。
 *
 * ## 0 件を「大丈夫」と言い切らない
 *
 * ここが見ているのは**機械で数えられるものだけ**である。
 * 「原稿が仮のまま」「電話番号が本物でない」は拾えるが、
 * **文章の中身が正しいかは見ていない。** 0 件を「公開してよい」と読ませない。
 */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

interface Finding {
    level: string;
    where: string;
    what: string;
    /**
     * 直す画面への行き先（UX 一周 2026-09-27）。
     * 「消してください」と言いながら行き先が無く、行を押しても何も起きなかった。
     * 行き先が決まらない指摘は null。
     */
    fix?: { href: string; label: string } | null;
}

interface MaintenanceReport {
    state: 'missing' | 'current' | 'stale';
    checkedAt: string | null;
    checks: { key: string; label: string; status: string; message: string }[];
}
interface ReleaseUpdateReport {
    state: 'missing' | 'invalid' | 'stale' | 'current';
    checkedAt: string | null;
    currentVersion: string | null;
    candidateVersion: string | null;
    signatureVerified: boolean;
    archiveVerified: boolean;
    message: string;
}
const props = defineProps<{
    findings: Finding[];
    checkedAt: string;
    deployGuide: { document: string; readmeSection: string } | null;
    maintenance: MaintenanceReport;
    releaseUpdate: ReleaseUpdateReport;
}>();

// 古い結果・構成変更後の結果を合格として表示しない。
function statusLabel(status: string): string {
    if (props.maintenance.state !== 'current') {
        return '再確認が必要';
    }

    return (
        (
            {
                pass: '確認済み',
                warn: '期限に注意',
                fail: '要対応',
                unknown: '未確認',
            } as Record<string, string>
        )[status] ?? '未確認'
    );
}

const blockers = computed(() =>
    props.findings.filter((f) => f.level === 'blocker'),
);
const others = computed(() =>
    props.findings.filter((f) => f.level !== 'blocker'),
);
const needsMailConfiguration = computed(() =>
    props.findings.some((finding) => finding.where === 'メール設定'),
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: '公開前チェック', href: '/admin/readiness' }],
    },
});

/**
 * この画面の日時は**日本時間で出す**（直し 4 回目）。
 *
 * 線の上は ISO8601（UTC の `Z` 付き）で来る。`toLocaleString('ja-JP')` だけだと
 * **見ている端末の時間帯**で出るので、時間帯が UTC の端末では日本時間から 9 時間ずれ、
 * ダッシュボードの「公開前チェック M月D日 H:MM」（サーバーが日本時間で作る）と食い違う。
 * 利用者は日本の事業者なので、時間帯を明示して端末に依存させない。
 */
function japanTime(iso: string): string {
    return new Date(iso).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo' });
}

/** 保守で要対応があるときは、畳んだ記録を最初から開いて見せる */
const serverNeedsAttention = computed(
    () =>
        props.maintenance.state === 'stale' ||
        (props.maintenance.state === 'current' &&
            props.maintenance.checks.some((check) => check.status === 'fail')),
);
</script>

<template>
    <Head title="公開前チェック" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">公開前チェック</h1>
            <p class="text-sm text-muted-foreground">
                公開する前に直しておきたいものを、いまのサイトから数えました。
            </p>
        </div>

        <div
            v-if="findings.length === 0"
            class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm"
            data-testid="readiness-clear"
        >
            <p class="font-medium">直すものは見つかりませんでした。</p>
            <p class="mt-1 text-muted-foreground">
                ここで見ているのは、機械で数えられるものだけです（開けないページ・
                「準備中」などの言葉・仮の電話番号・問い合わせの届け先）。
                <strong>文章の中身が正しいかは見ていません。</strong>
            </p>
        </div>

        <div v-else class="flex flex-col gap-6">
            <section v-if="blockers.length > 0">
                <h2 class="text-base font-semibold">
                    公開前に直したいもの（{{ blockers.length }} 件）
                </h2>
                <ul class="mt-2 divide-y rounded-lg border">
                    <li
                        v-for="(f, i) in blockers"
                        :key="`b-${i}`"
                        class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:gap-3"
                        data-testid="readiness-blocker"
                    >
                        <Badge variant="destructive" class="w-fit shrink-0">
                            要対応
                        </Badge>
                        <span class="text-sm">{{ f.what }}</span>
                        <span
                            class="text-sm text-muted-foreground sm:ml-auto sm:shrink-0"
                        >
                            {{ f.where }}
                        </span>
                        <component
                            v-if="f.fix"
                            :is="f.fix.href.startsWith('#') ? 'a' : Link"
                            :href="f.fix.href"
                            class="w-fit shrink-0 rounded-md border px-3 py-1 text-sm font-medium hover:bg-muted"
                            data-testid="readiness-fix"
                        >
                            {{ f.fix.label }}
                        </component>
                    </li>
                </ul>
            </section>

            <section v-if="others.length > 0">
                <h2 class="text-base font-semibold">
                    見ておきたいもの（{{ others.length }} 件）
                </h2>
                <ul class="mt-2 divide-y rounded-lg border">
                    <li
                        v-for="(f, i) in others"
                        :key="`o-${i}`"
                        class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:gap-3"
                    >
                        <Badge variant="secondary" class="w-fit shrink-0">
                            確認
                        </Badge>
                        <span class="text-sm">{{ f.what }}</span>
                        <span
                            class="text-sm text-muted-foreground sm:ml-auto sm:shrink-0"
                        >
                            {{ f.where }}
                        </span>
                        <Link
                            v-if="f.fix"
                            :href="f.fix.href"
                            class="w-fit shrink-0 rounded-md border px-3 py-1 text-sm font-medium hover:bg-muted"
                            data-testid="readiness-fix"
                        >
                            {{ f.fix.label }}
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <p class="text-sm text-muted-foreground">
            今回の確認：{{ japanTime(checkedAt) }}
        </p>
        <section
            v-if="deployGuide"
            id="deploy"
            class="scroll-mt-6 rounded-lg border p-4 text-sm"
        >
            <h2 class="text-base font-semibold">次はサーバーに出す</h2>
            <p class="mt-2">用意するもの：</p>
            <ul class="mt-2 list-disc space-y-2 pl-5">
                <li>
                    XServer の契約（どのプランでも可・PHP 8.3 以上が使えること）
                </li>
                <li>
                    公開に使うドメイン（初期ドメイン「サーバーID.xsrv.jp」でも可）
                </li>
                <li>SSH の鍵（サーバーパネルで公開鍵を登録）</li>
                <li>
                    XServer の API キー（あると DB・SSL・Cron などの準備を
                    <code>agentcms deploy setup</code>
                    で自動でできます。無ければサーバーパネルで手作業）
                </li>
            </ul>
            <p class="mt-3">
                手順書は、zip を展開したフォルダの
                <code>{{ deployGuide.document }}</code>
                にあります。管理画面からは開けません。README_FIRST.md の
                {{ deployGuide.readmeSection }} にも同じ案内があります。
            </p>
            <!--
                手順書 §4（デプロイ設定）が抜けていた（直し 4 回目の採点）。deploy の
                setup / doctor / plan / staging はどれも最初に .agentcms/deploy/<target>.json を読み、
                無ければその場で止まる（packages/cli/src/deploy/config.ts）。だから設定を作る段を先頭に置く。
            -->
            <p class="mt-3">進める順番：</p>
            <ol
                class="mt-2 list-decimal space-y-2 pl-5"
                data-testid="deploy-steps"
            >
                <li>
                    デプロイ設定を作る（手順書 §4）：
                    <!-- 長いパスはスマホ幅で枠からはみ出した（390px で実測）。どこでも折り返す -->
                    <code class="break-all"
                        >cp .agentcms/deploy/staging.example.json
                        .agentcms/deploy/staging.json</code
                    >
                    でコピーし、ホスト・サーバーID・ドメイン・鍵の場所を書きます。パスワードなどの秘密は、このファイルに書きません。
                </li>
                <li>
                    サーバー側を準備する（手順書 §2・§5）：API キーがあれば
                    <code>agentcms deploy setup</code
                    >、無ければサーバーパネルで手作業。サーバーの
                    <code>shared/.env</code> も埋めます。
                </li>
                <li>
                    <code>agentcms deploy doctor</code> →
                    <code>agentcms deploy plan</code> →
                    <code>agentcms deploy staging</code>
                    の順に、まずステージングで確かめます（手順書
                    §6）。本番は手順書の §12 を使ってください。
                </li>
            </ol>
            <p class="mt-3">
                この画面からは公開しません。文章の内容も確かめ、公開の直前にもう一度チェックしてください。
            </p>
        </section>
        <p v-else class="text-sm text-muted-foreground">
            直すものが 0 件になったら、次はサーバーに出す手順をここに出します。
        </p>

        <section
            v-if="needsMailConfiguration"
            id="mail-settings-guide"
            tabindex="-1"
            aria-labelledby="mail-settings-heading"
            class="scroll-mt-6 rounded-lg border p-4 text-sm"
        >
            <h2 id="mail-settings-heading" class="text-base font-semibold">
                メール配信の設定手順
            </h2>
            <p class="mt-2">
                現在はメールを配信しない設定です。フォームの受信内容は管理画面で確認できますが、通知メールは届きません。
            </p>
            <ol class="mt-2 list-decimal space-y-2 pl-5">
                <li>
                    運用担当者が、環境設定の MAIL_MAILER
                    と送信サーバー情報を確認します。
                </li>
                <li>
                    ローカル確認では
                    Mailpit、本番では使用するメール配信サービスを設定し、設定を反映します。
                </li>
                <li>
                    フォームからテスト送信し、受信一覧と通知メールの両方を確認してから、公開前チェックを再実行します。
                </li>
            </ol>
            <Link
                href="/admin/forms"
                class="mt-3 inline-flex min-h-11 items-center underline underline-offset-4"
            >
                フォームを開く
            </Link>
        </section>

        <!--
            配布版と定期保守は、**サーバーを管理する人**が見るもの（UX 一周 2026-09-28）。
            店の人が公開前に開く画面の先頭に「署名と構造契約」「DBの移行」が並び、
            直すべきものより先に、意味の分からない不安が来ていた。
            直すべきものを先に出し、こちらは畳む。**要対応があるときだけ開いて出す。**
        -->
        <details
            class="rounded-lg border p-4"
            data-testid="server-admin-reports"
            :open="serverNeedsAttention"
        >
            <summary class="cursor-pointer font-semibold">
                サーバー管理者向けの記録（配布版の確認・定期保守）
            </summary>
            <p class="mt-2 text-sm text-muted-foreground">
                サーバーを管理している人が確かめる項目です。お店や会社の方は、ここは管理している人に見てもらってください。
            </p>
            <div class="mt-4 flex flex-col gap-4">
                <section
                    class="rounded-lg border p-4"
                    aria-label="配布版の確認"
                    data-testid="release-update-report"
                >
                    <h2 class="font-semibold">配布版の確認</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        サーバー管理者が確認した配布物の記録です。この画面は外部の更新情報を自動取得しません。
                    </p>
                    <p v-if="releaseUpdate.checkedAt" class="mt-2 text-sm">
                        確認日時：{{ japanTime(releaseUpdate.checkedAt) }}
                    </p>
                    <p v-if="releaseUpdate.currentVersion" class="mt-2 text-sm">
                        利用中：{{ releaseUpdate.currentVersion
                        }}<span v-if="releaseUpdate.candidateVersion">
                            ／ 候補：{{ releaseUpdate.candidateVersion }}</span
                        >
                    </p>
                    <p class="mt-3 text-sm" role="status">
                        {{ releaseUpdate.message }}
                    </p>
                    <div
                        v-if="
                            releaseUpdate.state === 'current' &&
                            releaseUpdate.signatureVerified
                        "
                        class="mt-3 flex flex-wrap gap-2"
                    >
                        <Badge variant="secondary">署名確認済み</Badge>
                        <Badge variant="secondary">{{
                            releaseUpdate.archiveVerified
                                ? '配布物照合済み'
                                : '配布物は未照合'
                        }}</Badge>
                    </div>
                    <p class="mt-3 text-xs text-muted-foreground">
                        署名と構造契約の一致だけでは、DBの移行が不要とは判断できません。更新作業はまだ実行されていません。
                    </p>
                </section>

                <section
                    class="rounded-lg border p-4"
                    aria-label="定期保守の確認"
                    data-testid="maintenance-report"
                >
                    <h2 class="font-semibold">定期保守の確認</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        PHP依存ライブラリとHTTPS証明書を毎日確認します。確認にはサーバーの定期実行設定が必要です。
                    </p>
                    <p v-if="maintenance.checkedAt" class="mt-2 text-sm">
                        最終確認：{{ japanTime(maintenance.checkedAt) }}
                    </p>
                    <p
                        v-if="maintenance.state === 'missing'"
                        class="mt-3 text-sm"
                        role="status"
                    >
                        まだ確認結果がありません。サーバー管理者に定期確認の設定を依頼してください。
                    </p>
                    <p
                        v-else-if="maintenance.state === 'stale'"
                        class="mt-3 text-sm text-amber-700 dark:text-amber-400"
                        role="status"
                    >
                        確認から48時間を超えたか、依存関係・公開URLが変わっています。再確認が必要です。
                    </p>
                    <ul v-if="maintenance.checks.length" class="mt-3 divide-y">
                        <li
                            v-for="check in maintenance.checks"
                            :key="check.key"
                            class="flex flex-col gap-1 py-3 sm:flex-row sm:gap-4"
                        >
                            <span class="font-medium sm:w-40 sm:shrink-0">{{
                                check.label
                            }}</span>
                            <div>
                                <Badge
                                    :variant="
                                        maintenance.state === 'current' &&
                                        check.status === 'fail'
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                    >{{ statusLabel(check.status) }}</Badge
                                >
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ check.message }}
                                </p>
                            </div>
                        </li>
                    </ul>
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer">
                            サーバー管理者向けの確認手順
                        </summary>
                        <p class="mt-2">
                            サーバー上で
                            <code>php artisan agentcms:maintenance</code>
                            を実行し、結果を確認してください。定期実行には毎分の
                            <code>php artisan schedule:run</code>
                            を設定します。コードの自動更新は行いません。
                        </p>
                    </details>
                </section>
            </div>
        </details>
    </div>
</template>
