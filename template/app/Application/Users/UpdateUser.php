<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\SafeFailureLog;
use App\Support\SessionInvalidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * 利用者の基本情報を更新する（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ここで変えられるのは **名前とメールアドレスだけ**。
 * ロールと有効状態は専用の操作（`ChangeUserRole` / `DeactivateUser`）を通す
 * （同じフォームから権限が動くと、確認と監査の粒度が合わなくなる）。
 */
final class UpdateUser implements Operation
{
    /*
     * メールアドレスを変えたあと **何を送ったか**（Correction 4）。
     *
     * 画面の案内は「送れたか」だけでは決まらない。
     * 招待をやり直したのか、確認メールを送ったのかで、
     * 失敗したときの復帰手順がまったく違うので、種別を明示して返す。
     */
    public const DELIVERY_NONE = 'none';

    public const DELIVERY_INVITATION = 'invitation';

    public const DELIVERY_VERIFICATION = 'verification';

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly UserPresenter $presenter,
        private readonly SessionInvalidator $sessions,
    ) {}

    public function operationId(): string
    {
        return 'user.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param  array<string, mixed>  $input */
    public function execute(int $id, array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $user = User::query()->find($id);

        if ($user === null) {
            return $this->outcome->record($this, $context, Result::notFound('利用者が見つかりません'));
        }

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
        ], attributes: ['name' => '名前', 'email' => 'メールアドレス']);

        if ($validator->fails()) {
            return $this->outcome->record($this, $context, Result::validationError($this->messages($validator), '入力内容を確認してください'));
        }

        $data = $validator->validated();
        $changed = array_keys(array_filter([
            'name' => (string) $user->name !== (string) $data['name'],
            'email' => (string) $user->email !== (string) $data['email'],
        ]));

        $emailChanged = in_array('email', $changed, true);

        /*
         * **メールアドレスを変える前に**「まだ使い始めていない人か」を見る（Correction 4）。
         * 判定材料（invited_at / last_login_at）はアドレスでは動かないが、
         * どちらの案内を送るかを決める分岐なので、変更前の状態で確定させる。
         */
        $pendingInvitation = $user->isPendingInvitation();

        $user->fill(['name' => (string) $data['name'], 'email' => (string) $data['email']]);

        /*
         * メールアドレスを変えたら **確認済みの印を戻す**（Correction 4）。
         * 新しいアドレスが本人のものだと確かめられていないので、
         * `verified` を要求する管理画面へは確認を終えるまで戻れない。
         * 同時に既存 session も失効させ、開いたままの画面を止める。
         */
        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->save();

        $delivery = self::DELIVERY_NONE;
        $delivered = true;

        if ($emailChanged) {
            $this->sessions->forgetUser((int) $user->getKey());

            /*
             * **まだ使い始めていない人には確認メールを送らない**（Correction 4）。
             *
             * 招待中の利用者はパスワードをまだ決めていない。そこへ
             * 「メールアドレスを確認してください」だけ送っても、
             * 本人はログインできないので何も進まない。
             * しかも旧アドレス宛に出した招待リンクは **新しいアドレスでは使えない**
             * （reset token はメールアドレスへ紐づく）ので、
             * 何も送り直さないと利用開始の手段が完全に消える。
             *
             * だから招待中の相手には **新しいアドレス宛の招待をやり直す**。
             */
            $delivery = $pendingInvitation ? self::DELIVERY_INVITATION : self::DELIVERY_VERIFICATION;
            $delivered = $pendingInvitation
                ? $this->reinvite($user, $context)
                : $this->sendVerification($user);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                [
                    'user' => $this->presenter->present($user->refresh(), $context),
                    'deliveryKind' => $delivery,
                    'delivered' => $delivered,
                ],
                ['deliveryKind' => $delivery, 'delivered' => $delivered],
            ),
            resourceType: 'user',
            resourceId: (string) $user->getKey(),
            // **値は残さない**。変わった項目名と、何をどう送れたかだけ
            metadata: [
                'fields' => $changed,
                'emailVerificationReset' => $emailChanged,
                'deliveryKind' => $delivery,
                'delivered' => $delivered,
            ],
        );
    }

    /**
     * 確認メールを送る。**送れなくても更新は巻き戻さない**（Correction 2-8）。
     *
     * ## 例外を素通りさせると何が起きるか
     *
     * `save()` は既に済んでいる。ここで例外が外へ出ると
     * **メールアドレスは変わり、確認済みは外れ、session も失効した状態のまま
     * 画面には 500 が出る**。しかも監査は 1 行も残らない
     * （記録は結末を返す直前にあるため、そこまで到達しない）。
     * 利用者から見れば「失敗した」のに実際は変わっている、という
     * いちばん復旧しにくい形になる。
     *
     * ## だから送信の失敗は結末として返す
     *
     * 状態変更はそのまま確定させ、**送れなかったことを呼び出し側へ返す**。
     * 画面は日本語で再送を促し、本人は確認メールの再送から復帰できる。
     * 例外は `report()` で運用側のログにだけ残す（宛先は載せない）。
     */
    private function sendVerification(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $e) {
            // 確認メールの本文には署名付き URL が載る。例外へ抱かれ得るので中身は残さない
            SafeFailureLog::record('確認メールを送信できませんでした', $e);

            return false;
        }
    }

    /**
     * 招待中の相手へ、**新しいアドレス宛の招待をやり直す**（Correction 4）。
     *
     * token は保存済みの新アドレスに対して発行し直す（旧アドレス宛のものは使えない）。
     * `invited_at` は **送れたときだけ**進める — 送れていないのに
     * 「さっき送った」と表示されると、届いていない相手を待ち続けることになる。
     */
    private function reinvite(User $user, OperationContext $context): bool
    {
        try {
            $user->notify(new UserInvitation(
                UserInvitation::issueTokenFor($user),
                $context->actorLabel !== '' ? $context->actorLabel : '管理者',
                $user->role()->label(),
            ));

            $user->forceFill(['invited_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            SafeFailureLog::record('招待メールを送信できませんでした（メールアドレス変更）', $e);

            return false;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function messages(\Illuminate\Validation\Validator $validator): array
    {
        return array_map(
            static fn (array $messages): array => array_values($messages),
            $validator->errors()->toArray(),
        );
    }
}
