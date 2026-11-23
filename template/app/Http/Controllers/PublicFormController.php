<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Forms\FormRules;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Forms\FormValueText;
use AgentCms\Core\Forms\NotifyRecipients;
use AgentCms\Core\Mail\RecordedMailer;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Spam\SpamProtection;
use AgentCms\Core\Spam\SpamReason;
use AgentCms\Core\Spam\SpamSubject;
use App\Mail\FormAdminNotification;
use App\Mail\FormAutoReply;
use App\Support\ContactEntry;
use App\Support\PublicFormInput;
use App\Support\PublicFormLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Modules\Notifications\NotificationType;
use Modules\Notifications\Notifier;

/**
 * 公開フォーム（Phase 9.12 / STAGE 25C・SPEC §C）。
 *
 * ## 送信の順序は変えない（ADR-0005 / OG12）
 *
 * ```
 * Validation → bot 対策 → DB 保存 → 管理者通知 → 自動返信 → 結果記録 → Push
 * ```
 *
 * **保存はメールより先。** 以降が全部失敗しても、届いた内容は残る。
 * `RecordedMailer` は例外ではなく `?string` を返し、`Notifier` は例外を投げない。
 * だから 1 通の失敗が他の経路や保存へ波及しない。
 *
 * ## 確認画面を経ても、もう一度検証する（§C.2）
 *
 * 確認画面の hidden は書き換えられる。「確認を通ったから正しい」とはしない。
 *
 * ## 弾き方は理由で変える（§F.4）
 *
 * honeypot は**成功したように見せる**（bot に判定を教えない — OG10）。
 * rate limit は 429（人が連打しただけなら、直せると分かる応答を返す）。
 * Turnstile は入力画面へ差し戻す（やり直せば通る）。
 */
final class PublicFormController
{
    public function show(string $key, FormFields $fields): View
    {
        return $this->inputScreen($this->visible($key), $fields);
    }

    /** @param array<string, string> $headers */
    public function rateLimited(Request $request, string $key, FormFields $fields, array $headers): Response
    {
        $form = $this->visible($key);
        $values = $this->knownValues($request, $fields, $form);
        // 宣言済みの項目だけ戻す。CSRF・ボット確認の使い捨て token は残さない。
        $request->session()->flashInput($values);
        $view = $this->inputScreen($form, $fields, $values)->with(
            'rateLimitMessage', '少し待ってからもう一度送ってください。入力した内容は残っています。',
        );

        return response($view, 429, $headers + ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
    }

    /**
     * 確認画面（`confirm_step` が true のときだけ通る）。
     *
     * ここでは **bot 対策を行わない**。判定は保存の直前に 1 回だけ行う
     * （Turnstile の token は使い捨てで、2 回検証すると必ず落ちる）。
     */
    public function confirm(Request $request, string $key, FormFields $fields): View|RedirectResponse
    {
        $form = $this->visible($key);

        if (! $form->status()->acceptsSubmissions()) {
            return $this->closed($form);
        }

        if (! $form->confirm_step) {
            // 確認画面を切っているフォームへ直接叩かれた。入力画面へ戻す
            return redirect()->route('forms.show', ['key' => $form->key]);
        }

        /*
         * 「入力へ戻る」。**書いたものを持って戻る。**
         * ただの GET リンクにすると、長い相談文を書いた人が空の画面に戻される。
         * 検証はしない — 直しに戻る人を、その途中の値で叱らない。
         */
        if ($request->boolean('back')) {
            return $this->inputScreen($form, $fields, $this->knownValues($request, $fields, $form));
        }

        $validated = $this->validateInput($request, $form, $fields);

        if ($validated instanceof RedirectResponse) {
            return $validated;
        }

        $rows = [];

        foreach ($fields->for($form) as $field) {
            $rows[] = [
                'label' => $field->label,
                'text' => FormValueText::of($field, $validated[$field->key] ?? null),
            ];
        }

        return view('forms.confirm', [
            'form' => $form,
            'rows' => $rows,
            'values' => $validated,
            'action' => route('forms.store', ['key' => $form->key]),
            // 戻るも POST（値を持って戻るため）。宛先は確認と同じ route
            'backUrl' => route('forms.confirm', ['key' => $form->key]),
            'submitLabel' => $form->submit_label,
            'turnstileToken' => (string) $request->input('cf-turnstile-response', ''),
        ] + $this->layoutFor($form->name.'（入力内容の確認）'));
    }

    public function store(
        Request $request,
        string $key,
        FormFields $fields,
        SpamProtection $spam,
        RecordedMailer $mailer,
        SettingsStore $settings,
        Notifier $notifier,
    ): RedirectResponse {
        $form = $this->visible($key);

        if (! $form->status()->acceptsSubmissions()) {
            return $this->closed($form);
        }

        // 1. Validation
        $validated = $this->validateInput($request, $form, $fields);

        if ($validated instanceof RedirectResponse) {
            return $validated;
        }

        /*
         * 2. bot 対策。
         *
         * 上限は**フォーム 1 本ごと**に数える。1 つのフォームへの連打で、
         * 同じ人が別のフォームを送れなくなるのは行き過ぎである。
         */
        $blocked = $spam->inspect($request, new SpamSubject(
            kind: 'form',
            id: $form->getKey(),
            ratePerMinute: (int) config('agentcms.forms.rate_limit_per_minute', 5),
        ));

        if ($blocked !== null) {
            return $this->rejected($form, $blocked);
        }

        /*
         * 二度押しは 1 件にする（UX 一周 8 周目 R8-3: スマホで「送信」を二度タップすると 2 件受け付け、
         * 店にもお客さんにも同じメールが 2 通ずつ届いた）。同じ人・同じフォーム・同じ内容が 30 秒以内に
         * 来たら、保存もメールもせずに完了画面へ（1 回目はもう受け付けている）
         */
        $fingerprint = 'form-submit:'.hash('sha256', $form->getKey().'|'.$this->ipHash($request).'|'.json_encode($validated, JSON_UNESCAPED_UNICODE));

        if (! Cache::add($fingerprint, true, 30)) {
            return $this->completed($form);
        }

        // 3. DB へ保存（メールより先。以降の失敗はデータを失わせない）
        $submission = FormSubmission::query()->create([
            'form_id' => $form->getKey(),
            // 表示名は **並び付き**で持つ（MySQL はオブジェクトのキー順を保存しない）
            'data' => $validated + [
                FormSubmission::LABELS_KEY => FormSubmission::labelPairs($fields->labelsFor($form)),
            ],
            'status' => 'received',
            'ip_hash' => $this->ipHash($request),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // 4. 管理者通知 → 5. 自動返信 → 6. 結果記録
        $siteName = (string) $settings->get('site_name', config('app.name'));
        // 通知先は複数可（R-11 ⑤）。決め方は 1 か所（管理画面からの再送も同じ順で読む）
        $adminTo = NotifyRecipients::forForm($form->notify_to, (string) $settings->get('contact_email'));

        $adminError = $adminTo === []
            ? '通知先が設定されていません'
            : $mailer->send(new FormAdminNotification($form, $submission), $adminTo);

        $replyTo = $this->autoreplyTo($form, $fields, $validated);
        $replyError = $replyTo === null
            ? null
            : $mailer->send(new FormAutoReply($form, $submission, $siteName), $replyTo);

        $submission->forceFill([
            'admin_notified_at' => $adminError === null ? now() : null,
            'admin_notification_error' => $adminError,
            'autoreply_sent_at' => $replyTo !== null && $replyError === null ? now() : null,
            'autoreply_error' => $replyError,
        ])->save();

        /*
         * 7. Push（payload は最小情報だけ。本文と連絡先は載せない）。
         *
         * 宛先は **`route()` ではなく組み立てた URL**。受信一覧の画面は 25D で入る。
         * 公開面が管理画面の route の有無に依存すると、片方だけ配った環境で
         * 送信そのものが 500 になる。
         */
        $detailUrl = url('/admin/forms/'.$form->key.'/submissions');

        $notifier->send(
            NotificationType::ContactNew,
            '新しいフォーム送信',
            "「{$form->name}」に新しい送信が届きました",
            $detailUrl,
        );

        if ($adminError !== null) {
            $notifier->send(
                NotificationType::ContactEmailFailed,
                'メール通知の送信に失敗しました',
                "「{$form->name}」の通知メールを送れませんでした。管理画面で内容を確認してください。",
                $detailUrl,
            );
        }

        return $this->completed($form);
    }

    public function complete(string $key): View|RedirectResponse
    {
        $form = $this->visible($key);

        // 直接 URL を叩いただけの人へ「送信しました」と言わない
        if (session('form_completed') !== $form->key) {
            return redirect()->route('forms.show', ['key' => $form->key]);
        }

        return view('forms.complete', [
            'form' => $form,
            'message' => trim((string) $form->complete_message)
                ?: '送信しました。お問い合わせありがとうございます。',
        ] + $this->layoutFor($form->name.'（送信完了）'));
    }

    // --- 内部 ----------------------------------------------------------

    /**
     * 確認・完了画面が載る layout と題名（R-12 / 受付の一本化）。
     *
     * **入口と出口を同じ意匠に載せる。** 入力画面だけ案件の意匠で、送った先が
     * kit 既定になっていると、押した人からは別のサイトへ飛んだように見える
     * （2026-08-30 に同梱見本で実測。ヘッダーの項目名まで変わっていた）。
     *
     * 判断は `PublicFormLayout` の 1 か所（題名の作り方の差もあちらが吸収する）。
     *
     * @return array{layout: string, title: string}
     */
    private function layoutFor(string $heading): array
    {
        $layout = app(PublicFormLayout::class);
        $siteName = (string) app(SettingsStore::class)->get('site_name', config('app.name'));

        return [
            'layout' => $layout->name(),
            'title' => $layout->title($heading, $siteName),
        ];
    }

    /** 下書きは**存在しない**（SPEC §C.1）。受付終了は画面を出す */
    private function visible(string $key): Form
    {
        $form = Form::query()->where('key', $key)->first();

        if ($form === null || ! $form->status()->isVisible()) {
            abort(404);
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $values  確認画面から持って戻った値（partial は `old()` を優先する）
     */
    private function inputScreen(Form $form, FormFields $fields, array $values = []): View
    {
        /** @var view-string $view */
        $view = $this->inputView();

        return view($view, app(PublicFormInput::class)->data($form, $values));
    }

    /**
     * どの view で描くか（R-12 Stage 2 / D）。
     *
     * **取り込んだ意匠の view が在れば、そちらで描く。** 無ければ kit 既定。
     * 判定は 1 か所（ここ）だけで、`ContactEntry::usesForm()` が見るのと**同じ名前**である
     * — 片方だけ在る状態を作らない。
     *
     * ▷ **全部のフォームがこの意匠になる。** 取り込んだ案件が持っている意匠は 1 つなので、
     * フォームごとに違う見た目にする理由が無い（見出しは `$form->name` が入る）。
     */
    private function inputView(): string
    {
        // 同梱見本の意匠を「使わない」にしてあれば kit 既定へ（判定は ContactEntry の 1 か所）
        return app(ContactEntry::class)->rendersImportedView()
            ? ContactEntry::IMPORTED_VIEW
            : 'forms.show';
    }

    /**
     * 検証。失敗したら**入力画面へ**戻す。
     *
     * `back()` を使わないのは、確認画面が POST の応答として描かれるためである
     * （referer が POST 専用 URL になり、戻り先が 405 になる）。
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function validateInput(Request $request, Form $form, FormFields $fields): array|RedirectResponse
    {
        $rules = FormRules::fromDeclarations($fields->declarationsFor($form));

        if ($rules === []) {
            // 項目を出せない束のフォームは公開できない（FormFields::problemFor）。
            // それでも到達したなら、空の送信を受けるより止める方がよい
            abort(404);
        }

        $validator = Validator::make($request->all(), $rules, [], $fields->labelsFor($form));

        if ($validator->fails()) {
            return redirect()
                ->route('forms.show', ['key' => $form->key])
                ->withErrors($validator)
                ->withInput();
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * request から**宣言してある項目だけ**を拾う。
     *
     * 丸ごと描き直すと、送られてきた任意の名前の値をそのまま画面へ戻すことになる。
     *
     * @return array<string, mixed>
     */
    private function knownValues(Request $request, FormFields $fields, Form $form): array
    {
        $values = [];

        foreach ($fields->for($form) as $field) {
            if ($request->has($field->key)) {
                $values[$field->key] = $request->input($field->key);
            }
        }

        return $values;
    }

    /**
     * 自動返信の宛先（SPEC §E.3 / OG6）。**推測しない。**
     *
     * @param  array<string, mixed>  $values
     */
    private function autoreplyTo(Form $form, FormFields $fields, array $values): ?string
    {
        $target = trim((string) $form->autoreply_field);

        if ($target === '') {
            return null;
        }

        foreach ($fields->for($form) as $field) {
            if ($field->key !== $target) {
                continue;
            }

            if ($field->type !== 'email') {
                return null;
            }

            $address = trim((string) ($values[$target] ?? ''));

            return $address === '' ? null : $address;
        }

        return null;
    }

    private function rejected(Form $form, SpamReason $reason): RedirectResponse
    {
        return match ($reason) {
            // 弾いたことを教えない（OG10）。保存はしていない
            SpamReason::Honeypot => $this->completed($form),
            SpamReason::RateLimit => abort(429, '送信が集中しています。しばらく待ってからお試しください。'),
            /*
             * **入力値は `withInput()` で必ず持ち帰る。**
             * ボット確認は、まだ済んでいない（widget の描画が終わっていない）ときにも
             * ここへ来る。長い相談文を書いた人に、書き直しをさせない。
             */
            SpamReason::Bot => redirect()
                ->route('forms.show', ['key' => $form->key])
                ->withErrors([
                    'cf-turnstile-response' => 'ボット確認が完了していません。'
                        .'画面の確認欄にチェックが入るのを待ってから、もう一度送信してください。',
                ])
                ->withInput(),
        };
    }

    private function completed(Form $form): RedirectResponse
    {
        return redirect()
            ->route('forms.complete', ['key' => $form->key])
            ->with('form_completed', $form->key);
    }

    /** 生の IP は持たない（9.10 と同じ形） */
    private function ipHash(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip === null ? null : hash('sha256', (string) config('app.key').'|'.$ip);
    }

    private function closed(Form $form): RedirectResponse
    {
        return redirect()
            ->route('forms.show', ['key' => $form->key])
            ->withErrors(['form' => 'このフォームは受付を終了しました。']);
    }
}
