<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\Role;
use App\Support\SafeFailureLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * 利用者を招待する（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ## パスワードを人に渡さない
 *
 * 作成時のパスワードは **暗号学的乱数**を内部で持つだけで、
 * 画面にも API 応答にも監査にもログにも出さない。
 * 本人はメールのリンクから自分で設定する。
 *
 * ## メールが送れなかったとき
 *
 * 利用者は **作成済み**である。ここで巻き戻すと、
 * 「登録されたのか分からないまま二重に作る」事故が起きる。
 * そこで作成は確定させ、**送信に失敗したことを明示して再送を促す**。
 */
final class InviteUser implements Operation
{
    /*
     * 設定 URL の **受け渡し方法**（STAGE #18B）。
     *
     * どちらでも「本人がパスワードを決める」ことは変わらない。
     * 違うのは URL を誰が運ぶかだけで、管理者が仮パスワードを
     * 決める方式は **どちらにも用意しない**。
     */
    public const DELIVERY_EMAIL = 'email';

    public const DELIVERY_MANUAL = 'manual';

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly UserPresenter $presenter,
        private readonly ConfirmationGate $confirmations,
        private readonly PasswordSetupLink $links,
    ) {}

    public function operationId(): string
    {
        return 'user.invite';
    }

    public function classification(): OperationClassification
    {
        // メール送信という外部影響を伴う
        return OperationClassification::ExternalEffect;
    }

    /** @param  array<string, mixed>  $input */
    public function execute(array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
            // 表に無い受け渡し方法は **黙って既定へ倒さず**拒否する
            'deliveryMethod' => ['sometimes', 'string', 'in:'.self::DELIVERY_EMAIL.','.self::DELIVERY_MANUAL],
        ], attributes: [
            'name' => '名前', 'email' => 'メールアドレス',
            'role' => '権限', 'deliveryMethod' => '受け渡し方法',
        ]);

        if ($validator->fails()) {
            return $this->outcome->record($this, $context, Result::validationError($this->messages($validator), '入力内容を確認してください'));
        }

        $data = $validator->validated();
        $role = Role::from((string) $data['role']);
        $delivery = (string) ($data['deliveryMethod'] ?? self::DELIVERY_EMAIL);
        $manual = $delivery === self::DELIVERY_MANUAL;

        /*
         * **オーナーを増やす招待は確認を通す**（Correction 6）。
         * オーナーは利用者管理を含む全権限なので、他のロールと同じ
         * 「フォームを送ったら即作成」にしない。
         *
         * 束縛には **確認画面に出した値をすべて**入れる（Correction 2-8）。
         * 名前を外していると「田中さんをオーナーにしますか？」と見せて
         * 承認させたトークンで、同じメールアドレス・同じ権限のまま
         * **別の表示名で作成**できてしまう。確認した内容と
         * 実際に起きることがずれる余地を残さない。
         */
        if ($role === Role::Owner || $role === Role::Developer) {
            $confirmation = $this->confirmations->require(
                $this->operationId(),
                $this->classification(),
                $context,
                [
                    'name' => (string) $data['name'],
                    'email' => (string) $data['email'],
                    'role' => $role->value,
                    // 受け渡し方法も束縛する。確認画面に出した説明と
                    // 実際に起きることをずらさないため（Correction 2-8 と同じ理由）
                    'deliveryMethod' => $delivery,
                ],
                summary: "{$data['name']} さんをオーナーとして招待します",
                impact: [
                    'オーナーは利用者の管理を含むすべての操作ができます',
                    $manual
                        ? 'メールは送りません。パスワード設定 URL をこの画面に 1 度だけ表示するので、安全な経路で本人へ渡してください'
                        : '招待した相手にパスワード設定の案内メールを送ります',
                ],
            );

            if ($confirmation !== null) {
                return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation));
            }
        }

        $user = new User;
        $user->fill([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            // 本人が再設定するまでの置き場。**誰にも渡さない**
            'password' => Hash::make(Str::random(64)),
        ]);
        // role / is_active は fillable ではない（一括代入で権限が動かないようにするため）
        $user->forceFill([
            'role' => $role->value,
            'is_active' => true,
            'invited_at' => now(),
            // 招待メールのリンクを開いた時点で本人確認が済むため、ここでは未確認のまま
            'email_verified_at' => null,
        ]);

        if ($manual) {
            return $this->createForManualHandover($user, $role, $context);
        }

        $user->save();

        $delivered = $this->sendInvitation($user, $context, $role);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                [
                    'user' => $this->presenter->present($user->refresh(), $context),
                    'deliveryMethod' => self::DELIVERY_EMAIL,
                    'delivered' => $delivered,
                ],
                ['deliveryMethod' => self::DELIVERY_EMAIL, 'delivered' => $delivered],
            ),
            resourceType: 'user',
            resourceId: (string) $user->getKey(),
            // メールアドレスも名前も残さない。誰に何の権限を与えたかだけ
            metadata: [
                'role' => $role->value,
                'deliveryMethod' => self::DELIVERY_EMAIL,
                'delivered' => $delivered,
            ],
        );
    }

    /**
     * 手動で受け渡す場合の作成（STAGE #18B）。
     *
     * ## 作成と URL 発行を同じ transaction に入れる
     *
     * メール経路では「作ったがメールが落ちた」を **作成を残して**扱う。
     * 送信の途中で外へ出てしまったかもしれず、消すと二重登録を招くからだ。
     *
     * 手動経路はそこが違う。**外の世界へは何も出ていない**ので、
     * URL を作れなかったときに利用者だけ残す理由が無い。
     * 残すと「招待中に見えるのに渡す URL が無い」という、
     * 画面からは直せない中途半端な行ができる。
     * だから両方を 1 つの transaction にまとめ、失敗したら何も作らない。
     * 案内も監査もそれに合わせる（「作成していません」）。
     *
     * `invited_at` はこの transaction の中でだけ入るので、
     * **URL を発行できたときにしか記録されない**。
     */
    private function createForManualHandover(User $user, Role $role, OperationContext $context): Result
    {
        try {
            $url = DB::transaction(function () use ($user): string {
                $user->save();

                return $this->links->issueFor($user);
            });
        } catch (Throwable $e) {
            /*
             * **生の例外を報告しない**。broker の insert が落ちた場合、
             * 例外メッセージには SQL の bindings として
             * メールアドレスとハッシュ化 token が並ぶ。
             */
            SafeFailureLog::record('パスワード設定 URL を発行できませんでした（代理作成）', $e);

            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('パスワード設定 URL を発行できなかったため、利用者を作成していません。もう一度お試しください。'),
                metadata: [
                    'role' => $role->value,
                    'deliveryMethod' => self::DELIVERY_MANUAL,
                    'delivered' => false,
                ],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                [
                    'user' => $this->presenter->present($user->refresh(), $context),
                    'deliveryMethod' => self::DELIVERY_MANUAL,
                    'delivered' => true,
                    // **URL は data にだけ載せる**。呼び出し側が 1 度だけ画面へ出す
                    'setupUrl' => $url,
                    'expiresInMinutes' => $this->links->expiresInMinutes(),
                ],
                ['deliveryMethod' => self::DELIVERY_MANUAL, 'delivered' => true],
            ),
            resourceType: 'user',
            resourceId: (string) $user->getKey(),
            // **URL も token もメールアドレスも記録しない**。方法と権限だけ
            metadata: [
                'role' => $role->value,
                'deliveryMethod' => self::DELIVERY_MANUAL,
                'delivered' => true,
            ],
        );
    }

    /** 送信できたかどうかを返す（失敗しても利用者は作成済みのまま） */
    private function sendInvitation(User $user, OperationContext $context, Role $role): bool
    {
        try {
            $user->notify(new UserInvitation(
                UserInvitation::issueTokenFor($user),
                $context->actorLabel !== '' ? $context->actorLabel : '管理者',
                $role->label(),
            ));

            return true;
        } catch (Throwable $e) {
            // token 発行もメール送信もこの try の中。どちらの例外も
            // メールアドレス・token・本文（＝設定 URL）を抱え得る
            SafeFailureLog::record('招待メールを送信できませんでした', $e);

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
