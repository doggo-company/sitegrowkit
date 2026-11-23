<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Redirects;

use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\RedirectMatchType;
use AgentCms\Core\Redirects\RedirectPath;
use AgentCms\Core\Redirects\RedirectPresenter;
use AgentCms\Core\Redirects\RedirectRule;
use AgentCms\Core\Redirects\RedirectValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 転送ルールを作る / 直す（Phase 9.10 / STAGE 23B・SPEC §E.1・§D.2）。
 *
 * ## 順序を固定する
 *
 *   1. `OperationPolicy`
 *   2. 更新なら対象の存在確認と**版**（`lock_version`）
 *   3. 形と輪の検査（`RedirectValidator`）
 *   4. transaction の中で保存。**`source_hash` はここで作り直す**
 *   5. `OperationAudit`
 *
 * ## hash を渡させない
 *
 * `source_hash` は照合の鍵で、正規化したパスから必ず作り直す（SPEC §B.2）。
 * 外から渡された値を使うと、**登録した内容と鍵がずれたルール**を作れてしまう。
 * `$fillable` から外してあるうえ、ここでも明示的に組み立てる。
 *
 * ## 重複は DB に決めさせる
 *
 * 同じ `(照合方法, パス)` は 1 つだけ。事前に「あるか」を見てから入れると、
 * 並走したときに 2 つ入る。一意制約に当ててから、その結果を読める形へ直す。
 */
final class SaveRedirect implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly RedirectValidator $validator,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'redirect.save';
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
        ?int $id = null,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $existing = null;
        $version = null;

        if ($id !== null) {
            $existing = RedirectRule::query()->find($id);

            if ($existing === null) {
                return Result::notFound("転送ルール（ID: {$id}）は見つかりません");
            }

            $version = $this->version($lockVersion);

            if ($version instanceof Result) {
                return $version;
            }
        }

        $errors = $this->validator->validate($input, $id);

        if ($errors !== []) {
            return Result::validationError($errors);
        }

        $type = RedirectMatchType::from((string) $input['match_type']);
        $source = (string) RedirectPath::normalize((string) $input['source_path']);

        $attributes = [
            'match_type' => $type->value,
            'source_path' => $source,
            // **鍵はここで作る。** 渡された値は使わない
            'source_hash' => RedirectPath::hash($type, $source),
            'target_url' => trim((string) $input['target_url']),
            'status_code' => (int) $input['status_code'],
            'is_enabled' => (bool) ($input['is_enabled'] ?? true),
            'keep_query' => (bool) ($input['keep_query'] ?? true),
            'note' => isset($input['note']) && is_string($input['note']) ? trim($input['note']) : null,
        ];

        try {
            $result = DB::transaction(function () use ($existing, $attributes, $input, $version): Result {
                if ($existing === null) {
                    $rule = new RedirectRule;
                    $rule->forceFill($attributes + [
                        'created_reason' => self::reason($input),
                        'hit_count' => 0,
                        'lock_version' => 0,
                    ]);
                    $rule->save();

                    return Result::success(['rule' => RedirectPresenter::rule($rule)]);
                }

                // **CAS。** 読んだ版と同じときだけ 1 つ進める
                $applied = RedirectRule::query()
                    ->whereKey($existing->getKey())
                    ->where('lock_version', $version)
                    ->update($attributes + [
                        'lock_version' => $version + 1,
                        'updated_at' => now(),
                    ]);

                if ($applied === 0) {
                    return Result::conflict(
                        'ほかの操作と重なったため保存できませんでした。画面を読み込み直してください。',
                    );
                }

                $existing->refresh();

                return Result::success(['rule' => RedirectPresenter::rule($existing)]);
            });
        } catch (QueryException $e) {
            // SQL を利用者へ出さない（ログにだけ残す）
            report($e);

            return $this->outcome->record(
                $this,
                $context,
                Result::validationError([
                    'source_path' => ['同じ転送元のルールが既にあります'],
                ]),
                resourceType: 'redirect',
                resourceId: $id === null ? null : (string) $id,
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'redirect',
            resourceId: (string) ($result->data['rule']['id'] ?? $id),
        );
    }

    /**
     * どうやってできたルールか。
     *
     * 外から `slug_change` を名乗らせない（V1 では自動生成が無いので、
     * それを名乗る行があったら記録が嘘になる）。
     *
     * @param  array<string, mixed>  $input
     */
    private static function reason(array $input): string
    {
        return ($input['created_reason'] ?? null) === RedirectRule::REASON_NOT_FOUND
            ? RedirectRule::REASON_NOT_FOUND
            : RedirectRule::REASON_MANUAL;
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
            // 新しい表なので移行期間は無い（9.9 と同じ判断）
            return Result::validationError(['lock_version' => ['版の指定が必要です']]);
        }

        return $input->version;
    }
}
