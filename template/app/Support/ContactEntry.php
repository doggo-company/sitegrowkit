<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\Concerns\ChecksViews;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * `/contact` が何を出すかを決める（R-12 Stage 1）。
 *
 * ## 予約 key
 *
 * 識別子が **`contact`** のフォームが公開されていれば、`/contact` はそれを出す。
 * `header` / `footer` のメニューと同じ「**システムが名前で参照する予約 key**」である。
 *
 * ## 出さないほうへ落ちる条件は 2 つ
 *
 * 1. **取り込み済みの案件**（`.agentcms/manifest/template.json` が在る）…
 *    取り込んだ `contact.html` は `modules/Contact/views/form.blade.php` へ**原本のデザインで**
 *    書き出されている（`importer/plan.ts` の `PAGE_TARGETS`）。切り替えると**その見た目が消える**ので、
 *    Stage 1 では触らない（移行は Stage 2）
 * 2. **`contact` のフォームが無い / 下書き** … 窓口を消さない。
 *    「設定を 1 つ忘れると問い合わせが受けられない」を作らない
 *
 * ## 落とさないが、黙らない
 *
 * どちらで落ちたかは `reason()` が返す。サイト設定の画面がそれを 1 行で出す —
 * **黙って従来のままにすると、運用者は「一本化されたつもり」になる**。
 */
final class ContactEntry
{
    use ChecksViews;

    /**
     * 同梱の見本の意匠（`forms.imported` の見本）を**使わない**と運用者が決めたか（UX 一周 2026-09-27）。
     *
     * 🔴 公開前チェックは `/contact` を「同梱の見本のまま公開されています」と指摘する。
     * フォームそのものは運用者が管理画面で直せる本物なのに、描く枠が見本の意匠
     * （脚注「本サイトは SiteGrowKit に同梱のサンプルサイトです」付き）なので、
     * **管理画面のどこからも消せない指摘**になっていた。これを入れると kit 既定の
     * `forms.show`（`layouts.public`）で描く。**この案件の意匠の view には効かない**
     * （見本だけを外す）。
     */
    public const SAMPLE_VIEW_OFF_SETTING = 'contact_sample_view_off';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly ?SettingsStore $settings = null,
    ) {}

    /**
     * フォームを描くのに `forms.imported` を使うか。**入力・確認・完了の全部がこれに従う**
     * （`PublicFormController::inputView()` と `PublicFormLayout`）。
     */
    public function rendersImportedView(): bool
    {
        if (! $this->hasImportedView()) {
            return false;
        }

        return $this->hasOwnImportedView() || ! $this->sampleViewOff();
    }

    /** 見本の意匠で描いているか（見本ページの一覧に出すかどうか） */
    public function rendersSampleView(): bool
    {
        return $this->rendersImportedView() && ! $this->hasOwnImportedView();
    }

    public function sampleViewOff(): bool
    {
        return ($this->settings ?? app(SettingsStore::class))->get(self::SAMPLE_VIEW_OFF_SETTING) === true;
    }

    public function setSampleViewOff(bool $off): void
    {
        ($this->settings ?? app(SettingsStore::class))->put([self::SAMPLE_VIEW_OFF_SETTING => $off]);
    }

    /** 予約された識別子。ここを変えると公開 URL の意味が変わる */
    public const RESERVED_KEY = 'contact';

    /**
     * 取り込んだ意匠でフォームを描く view（R-12 Stage 2 / D）。
     *
     * `template apply` が原本から作る。**在れば、取り込み済みの案件でも
     * フォームへ切り替えてよい**ということである — 意匠が失われないため。
     */
    public const IMPORTED_VIEW = 'forms.imported';

    /**
     * `/contact` をフォームで出すか。
     *
     * ## 取り込み済みでも、意匠が残せるなら切り替える（Stage 2 / D）
     *
     * Stage 1 は「**取り込み済みの案件は従来のまま**」に落としていた。切り替えると
     * 取り込んだデザインが消えるからである。**Stage 2 でその前提が変わった** —
     * `template apply` が**原本の意匠でフォームを描く view** を作れるようになったので、
     * それが在るなら切り替えても**意匠は 1 つも失われない**。
     *
     * ▷ **判定材料を「取り込んだかどうか」から「意匠を残せるかどうか」へ寄せた。**
     * 取り込み済みかどうかは、もう直接の理由ではない（**残せないときだけ**理由になる）。
     */
    public function usesForm(): bool
    {
        if ($this->publishedForm() === null) {
            return false;
        }

        return ! $this->imported() || $this->hasOwnImportedView();
    }

    /** 取り込んだ意匠の view が在るか（同梱見本を含む） */
    public function hasImportedView(): bool
    {
        return $this->viewExists(self::IMPORTED_VIEW);
    }

    /**
     * **この案件の**意匠の view が在るか（R-12 / 受付の一本化）。
     *
     * 🔴 **同梱見本を数えない。** kit は見本サイトの `/contact` の意匠で
     * `forms/imported.blade.php` を**同梱している**（一本化しても見本の見た目を
     * 変えないため）。それをそのまま数えると、**取り込み済みなのに
     * `template apply` がまだフォームの view を作っていない案件**で、
     * 「意匠は残せる」と誤って答えてしまう — 出るのは**その案件の意匠ではなく、
     * kit の見本（Green Leaf）の意匠**である。
     *
     * 見分けは `bundled-samples.ts` と**同じ目印**（先頭の `agentcms:bundled-sample`）。
     * kit が置いたものだけが名乗り、`template apply` の生成物は名乗らない。
     */
    public function hasOwnImportedView(): bool
    {
        if (! $this->hasImportedView()) {
            return false;
        }

        return ! $this->isBundledSample(self::IMPORTED_VIEW);
    }

    /** 従来のまま出している理由（フォームで出しているなら null） */
    public function reason(): ?string
    {
        if ($this->usesForm()) {
            return null;
        }

        if ($this->imported() && ! $this->hasOwnImportedView()) {
            return 'このサイトはテンプレートを取り込んでいるので、/contact は従来のお問い合わせのままです。'
                .'取り込んだデザインを残すためで、切り替えるには `agentcms template apply` を'
                .'もう一度実行して、取り込んだ意匠のフォームを作り直してください。';
        }

        return '識別子が「'.self::RESERVED_KEY.'」の公開中のフォームがないので、'
            .'/contact は従来のお問い合わせのままです。'
            .'フォームを作って識別子を「'.self::RESERVED_KEY.'」にすると、そちらが表示されます。';
    }

    /** 予約 key の公開中フォーム（無ければ null） */
    /**
     * `/contact` を閉じているか（UX 一周 6 周目 R6-1）。
     *
     * 窓口がフォーム（識別子 `contact`）なのに、そのフォームを下書きにした。店の人は「受付を止めた」
     * つもりなので、`/contact` は出さない（404）。以前はここで**同梱の見本の窓口**に戻り、
     * 見本の電話番号つきで受け付けを続け、通知は見本の宛先（admin@example.test）へ飛んでいた。
     *
     * テンプレートを取り込んだサイトで、まだ従来の窓口を使っている場合（`reason()` の 1 つ目）は、
     * フォームの状態で窓口は変わらないので閉じない。
     */
    public function closed(): bool
    {
        $form = Form::query()->where('key', self::RESERVED_KEY)->first();

        if ($form === null || $form->status()->isVisible()) {
            return false;
        }

        return ! $this->imported() || $this->hasOwnImportedView();
    }

    public function publishedForm(): ?Form
    {
        $form = Form::query()->where('key', self::RESERVED_KEY)->first();

        return $form !== null && $form->status()->isVisible() ? $form : null;
    }

    /**
     * 取り込み済みの案件か。
     *
     * **判定材料は取り込みの記録そのもの**（`template inspect` が置く）。
     * 生成物の有無で見ると、手で消した案件を取り違える。
     */
    private function imported(): bool
    {
        return is_file(base_path('.agentcms/manifest/template.json'));
    }
}
