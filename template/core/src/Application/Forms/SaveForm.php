<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Forms;

use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Forms\FormKey;
use AgentCms\Core\Forms\FormPresenter;
use AgentCms\Core\Forms\FormStatus;
use AgentCms\Core\Forms\NotifyRecipients;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * フォームを作る / 直す（Phase 9.12 / STAGE 25B・SPEC §E.1）。
 *
 * ## `key` は作るときだけ決まる
 *
 * 公開 URL（`/forms/{key}`）に出るので、あとから変えると
 * **既に配ってしまった URL が死ぬ**。名前（`name`）はいつでも変えられる。
 *
 * ## 公開するときだけ厳しくする
 *
 * 下書きのあいだは項目が無くても保存できる（作りかけを保存できないと使えない）。
 * **`published` にするときだけ**、項目が実際に出せる状態かを確かめる。
 * 「公開したのに入力欄が 1 つも無いフォーム」を外へ出さない。
 *
 * ## 自動返信は宛先を選ばせる（OG6）
 *
 * `autoreply_field` は**存在する項目**で、かつ `email` 型でなければならない。
 * 名前から推測すると、「連絡用メール」と「担当者メール」がある構成で誤送信する。
 */
final class SaveForm implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly FormFields $fields,
        private readonly OperationAudit $outcome,
        private readonly FieldGroupRepository $fieldGroups,
        private readonly FieldGroupWriter $fieldGroupWriter,
    ) {}

    public function operationId(): string
    {
        return 'form.save';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(
        array $input,
        OperationContext $context,
        ?string $key = null,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $existing = null;
        $version = null;

        if ($key !== null) {
            $existing = GetForm::find($key);

            if ($existing === null) {
                return Result::notFound("フォーム [{$key}] は存在しません");
            }

            $version = $this->version($lockVersion);

            if ($version instanceof Result) {
                return $version;
            }
        }

        $errors = $this->validate($input, $existing);

        if ($errors !== []) {
            return Result::validationError($errors);
        }

        $attributes = [
            'name' => trim((string) $input['name']),
            'description' => self::text($input['description'] ?? null),
            'field_group_id' => self::text($input['field_group_id'] ?? null),
            'status' => (string) ($input['status'] ?? FormStatus::Draft->value),
            'confirm_step' => (bool) ($input['confirm_step'] ?? true),
            'submit_label' => trim((string) ($input['submit_label'] ?? '')) ?: '送信する',
            'complete_message' => self::text($input['complete_message'] ?? null),
            'notify_to' => NotifyRecipients::normalize(self::text($input['notify_to'] ?? null)),
            'autoreply_field' => self::text($input['autoreply_field'] ?? null),
            'autoreply_subject' => self::text($input['autoreply_subject'] ?? null),
            'autoreply_body' => self::text($input['autoreply_body'] ?? null),
            'autoreply_signature' => self::text($input['autoreply_signature'] ?? null),
            'mail_from_name' => self::text($input['mail_from_name'] ?? null),
            'reply_to' => self::text($input['reply_to'] ?? null),
        ];

        try {
            $result = DB::transaction(function () use ($existing, $attributes, $input, $version, $context): Result {
                if ($existing === null) {
                    // **入力をそのまま使う。** 黙って小文字へ直さない（§validate）
                    $formKey = trim((string) ($input['key'] ?? ''));

                    if ($formKey === '') {
                        $formKey = $this->derivedFormKey(trim((string) $input['name']));
                    }

                    $form = new Form;
                    $form->forceFill($attributes + [
                        'key' => $formKey,
                        'lock_version' => 0,
                    ]);

                    /*
                     * **入力項目の置き場所を、作った時点で用意する**（R-8A ①）。
                     *
                     * これまでは作ったフォームが `field_group_id` を持たず、利用者は
                     * **別画面（カスタムフィールド）へ自力で移動して束を作り、戻ってきて選び直す**
                     * 必要があった（実測: 画面 2 つを 2 往復・保存 3 回）。
                     *
                     * **明示的に選ばれていたらそれを尊重する。** 共有の束を使う運用を壊さない。
                     */
                    if ((string) ($attributes['field_group_id'] ?? '') === '') {
                        $form->field_group_id = $this->createOwnFieldGroup(
                            $formKey,
                            $attributes['name'],
                            $context,
                        );
                    }

                    $form->save();

                    return Result::success(['form' => FormPresenter::form($form)]);
                }

                $applied = Form::query()
                    ->whereKey($existing->getKey())
                    ->where('lock_version', $version)
                    ->update($attributes + ['lock_version' => $version + 1, 'updated_at' => now()]);

                if ($applied === 0) {
                    return Result::conflict(
                        'ほかの操作と重なったため保存できませんでした。画面を読み込み直してください。',
                    );
                }

                $existing->refresh();

                return Result::success(['form' => FormPresenter::form($existing)]);
            });
        } catch (QueryException $e) {
            report($e);

            return Result::validationError(['key' => ['その識別子のフォームが既にあります']]);
        }

        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'form',
            resourceId: (string) ($result->data['form']['key'] ?? $key),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    private function validate(array $input, ?Form $existing): array
    {
        $errors = [];

        $name = $input['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $errors['name'][] = '名前を入力してください';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'][] = '名前が長すぎます（120 文字まで）';
        }

        if ($existing === null) {
            /*
             * **黙って小文字へ直さない。** 識別子は公開 URL に出て、しかも
             * あとから変えられない（下の分岐）。入力と違うものが URL になると、
             * 利用者は自分が何を作ったのか分からないまま配ることになる。
             *
             * **空なら書かせない**（R-11 ①）。名前から作れるなら作り、
             * 作れなければ通し番号へ落とす（`derivedFormKey`）。
             */
            $key = trim((string) ($input['key'] ?? ''));

            if ($key !== '' && preg_match(FormKey::PATTERN, $key) !== 1) {
                $errors['key'][] = '識別子は英小文字で始まり、英数字と - _ が使えます（2〜64 文字）';
            }
        } elseif (array_key_exists('key', $input) && (string) $input['key'] !== $existing->key) {
            // 配ってしまった URL を死なせない
            $errors['key'][] = '識別子はあとから変更できません';
        }

        $status = (string) ($input['status'] ?? FormStatus::Draft->value);
        $statusEnum = FormStatus::tryFrom($status);

        if ($statusEnum === null) {
            $errors['status'][] = '状態の指定が正しくありません';
        }

        $fieldGroupId = self::text($input['field_group_id'] ?? null);

        // **公開するときだけ厳しくする**（作りかけは保存できないと使えない）
        if ($statusEnum === FormStatus::Published) {
            $problem = $this->fields->problemFor($fieldGroupId);

            if ($problem !== null) {
                $errors['field_group_id'][] = '公開できません: '.$problem;
            }
        }

        $errors = [...$errors, ...$this->validateNotifyTo($input)];
        $errors = [...$errors, ...$this->validateReplyTo($input)];
        $errors = [...$errors, ...$this->validateAutoreply($input, $fieldGroupId, $existing)];

        return $errors;
    }

    /**
     * 返信先（R-11 ④）。**1 つだけ**。
     *
     * Reply-To に複数を並べると、返信が誰に届くかは受信側の実装次第になる。
     * 「返信を受ける先」は 1 つに決めてもらう。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    private function validateReplyTo(array $input): array
    {
        $value = self::text($input['reply_to'] ?? null);

        if ($value === null) {
            return [];
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return ['reply_to' => ['メールアドレスとして読めません: '.$value]];
        }

        return [];
    }

    /**
     * 通知先（複数可）。**「書けるが届かない」を経由させない**（R-11 ⑤）。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    private function validateNotifyTo(array $input): array
    {
        $parsed = NotifyRecipients::parse(self::text($input['notify_to'] ?? null));

        if ($parsed['invalid'] !== []) {
            return ['notify_to' => [
                'メールアドレスとして読めない行があります: '.implode(' / ', $parsed['invalid']),
            ]];
        }

        $stored = implode(',', $parsed['addresses']);

        if (mb_strlen($stored) > NotifyRecipients::MAX_LENGTH) {
            return ['notify_to' => [
                '通知先が多すぎます（合計 '.NotifyRecipients::MAX_LENGTH.' 文字まで）',
            ]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    private function validateAutoreply(array $input, ?string $fieldGroupId, ?Form $existing): array
    {
        $target = self::text($input['autoreply_field'] ?? null);

        if ($target === null) {
            return [];
        }

        // 判定に使う束は「これから保存する束」
        $probe = new Form;
        $probe->forceFill(['field_group_id' => $fieldGroupId]);

        foreach ($this->fields->for($probe) as $field) {
            if ($field->key !== $target) {
                continue;
            }

            return $field->type === 'email'
                ? []
                : ['autoreply_field' => ['自動返信の宛先には、メールアドレスの項目を選んでください']];
        }

        return ['autoreply_field' => ['その項目は入力項目にありません']];
    }

    private function version(LockVersionInput|int|null $lockVersion): int|Result
    {
        $input = $lockVersion instanceof LockVersionInput
            ? $lockVersion
            : ($lockVersion === null ? LockVersionInput::unspecified() : LockVersionInput::of($lockVersion));

        if ($input->rejected) {
            return Result::validationError(['lock_version' => ['版の指定が正しくありません']]);
        }

        if ($input->version === null) {
            return Result::validationError(['lock_version' => ['版の指定が必要です']]);
        }

        return $input->version;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * そのフォーム専用の Field Group を作り、id を返す（R-8A ①）。**作れなければ null**。
     *
     * ## 概念は増やさない
     *
     * 新しいテーブルも新しい種類も作らない。既存の overlay 機構がそのまま効くように、
     * **普通の Field Group を 1 つ作って、このフォームへ割り当てるだけ**である。
     * 画面にはこの束を「束」として見せない（R-8A ②）。
     *
     * ## 割当先の value は **フォームの key**
     *
     * これまで value は手打ちで、**検証もされず誰も読まなかった**（打ち間違えても正しく見えた）。
     * key を入れることで候補一覧を value で絞れるようになり、その嘘が消える。
     * key は `SaveForm` が**あとから変えさせない**ので、参照として古くならない。
     *
     * ## 保存先がまだ無いときは黙って諦める
     *
     * `field_groups` テーブルが無い環境（overlay 未導入）でフォームを作れなくしない。
     * その場合はこれまでどおり「入力項目が未設定のフォーム」ができる。
     */
    private function createOwnFieldGroup(string $formKey, string $formName, OperationContext $context): ?string
    {
        if (! $this->fieldGroups->isInstalled()) {
            return null;
        }

        return $this->fieldGroupWriter->create(
            $this->uniqueGroupKey($formKey),
            $formName,
            [],
            [['kind' => FieldGroupLocationKind::Form->value, 'value' => $formKey]],
            $context,
        );
    }

    /**
     * 束の key。**フォームの key から作るが、同じにはしない**（別の名前空間なので混ぜない）。
     *
     * 束の key はハイフンを許さない（`FieldGroupDraft::MACHINE_NAME`）ので `_` へ寄せる。
     * 寄せた結果ぶつかることがある（`a-b` と `a_b`）ので、空いている番号まで進める。
     */
    /**
     * 名前から識別子を作る（R-11 ①）。
     *
     * ## 作れないことを隠さない
     *
     * 使えるのは `^[a-z][a-z0-9_-]{1,63}$` だけなので、「お問い合わせ」からは 1 文字も取れない。
     * **無理に音訳しない**（読み方を推測すると、利用者の意図と違う URL が配られる）。
     * 取れないときは `form-1` のような通し番号へ落とす — 項目のキーと同じ作法。
     *
     * ## 衝突は連番
     *
     * 識別子は公開 URL で、**あとから変えられない**。既に在るものを奪わない。
     */
    private function derivedFormKey(string $name): string
    {
        // 規則の正本は `FormKey`。**同じ式を 2 度書かない**（指摘 41）
        $slug = FormKey::slugify($name);

        $taken = Form::query()->pluck('key')->all();
        $taken = array_flip(array_map(static fn ($k): string => (string) $k, $taken));

        $base = $slug !== '' ? $slug : 'form';

        if ($base !== 'form' && ! isset($taken[$base])) {
            return $base;
        }

        for ($n = 1; $n < 1000; $n++) {
            $candidate = FormKey::suffixed($base, (string) $n);

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        // ここまで来るのは同名が 999 個ある場合だけ。**推測で上書きしない**
        return FormKey::suffixed($base, substr((string) now()->getTimestampMs(), -6));
    }

    private function uniqueGroupKey(string $formKey): string
    {
        $base = 'form_'.str_replace('-', '_', $formKey);
        $base = mb_substr($base, 0, 60);

        $taken = [];

        foreach ($this->fieldGroups->all() as $record) {
            $taken[$record->key] = true;
        }

        if (! isset($taken[$base])) {
            return $base;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $base.'_'.$n;

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        // ここまで来るのは同名が 999 個ある場合だけ。**推測で上書きしない**
        return $base.'_'.substr((string) now()->getTimestampMs(), -6);
    }
}
