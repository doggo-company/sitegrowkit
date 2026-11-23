<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use App\Models\User;
use App\Support\Role;

/**
 * 利用者を壊さないための不変条件（PHASE WP-BASELINE / STAGE #18A）。
 *
 * **画面とサーバーで同じ規則にする**ため、判定はここ 1 箇所だけに置き、
 * 画面はここが返す真偽値を props で受け取ってボタンを出し分ける。
 *
 * | 規則 | 理由 |
 * | --- | --- |
 * | 最後の有効なオーナーは降格・無効化できない | 誰も管理できない CMS を作らない |
 * | 自分自身は無効化できない | 自分で自分を締め出せてしまう |
 * | 自分のロールは変更できない | 「うっかり降格して戻せない」を作らない（より安全な側へ倒す） |
 * | 物理削除しない | 監査の actor と過去の記録を失わない |
 */
final class UserGuard
{
    /**
     * オーナーと同じ全権限を持つロールか。制作者（UX 一周 2026-09-28）もオーナーと同じ重みなので、
     * 「最後のオーナー」を数えるときは両方を数える（制作者だけが残る形も、全権限の人が残る）。
     */
    private static function isFull(Role $role): bool
    {
        return $role === Role::Owner || $role === Role::Developer;
    }

    /**
     * 最後の有効なオーナーか（この人を降格・無効化すると owner が居なくなる）。
     *
     * 画面の出し分け用の **速い判定**。実際に適用する直前は
     * `lockOwnerCriticalChange()` が行ロック付きで決め直す（Correction 2-1）。
     */
    public function isLastActiveOwner(User $user): bool
    {
        return $this->otherActiveOwners($user) === 0 && self::isFull($user->role()) && $user->is_active;
    }

    /** 画面表示用の速い件数（ロックしない） */
    public function otherActiveOwners(User $user): int
    {
        return User::query()
            ->whereIn('role', [Role::Owner->value, Role::Developer->value])
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->count();
    }

    /**
     * 適用の直前に「オーナーが残るか」を **決定論的な順序のロック**で決める（Correction 2-1）。
     *
     * ## 以前の実装の何が壊れていたか
     *
     * 対象の 1 行を `lockForUpdate` してから、残りのオーナーを
     * `COUNT(*) ... FOR UPDATE` で数えていた。`role` に索引が無いので
     * この COUNT は **users 全行を走査してロック**する。
     * 結果、A を対象にした取引は「A → 全行」の順で、
     * B を対象にした取引は「B → 全行」の順でロックを取り、
     * 逆順の待ち合わせ（deadlock）が成立する。
     * MySQL が片方を殺すと、それは `QueryException` として素通しになり
     * 500 になる — 競合として扱われない。
     *
     * ## いまの実装
     *
     * **有効なオーナー全行 + 対象**を候補にし、**主キーの昇順で 1 行ずつ**
     * `lockForUpdate` する。すべての取引が同じ順序でしかロックを取らないので
     * 循環待ちが作れない（deadlock が起きない）。
     *
     * 候補の下読みはロック無しなので古いことがあるが、**数えるのは
     * 実際にロックした行だけ**にしてある。
     *
     * | 下読みとのズレ | 起きること | 安全か |
     * | --- | --- | --- |
     * | すでに降格済みの人が候補に居る | ロックして読み直し、オーナーでないので数えない | 安全（過少に数える） |
     * | 下読みの後にオーナーが増えた | 候補外なので数えない | 安全（過少に数える） |
     *
     * どちらも「実際より少なく数える」側にしか倒れないので、
     * オーナーが 0 人になる方向へは間違えない。
     *
     * @param  'role'|'deactivate'  $kind
     * @return array{user: ?User, refusal: ?Result}
     */
    public function lockOwnerCriticalChange(int $targetId, string $kind): array
    {
        $locked = $this->lockOwnerSet($targetId);
        $target = $locked[$targetId] ?? null;

        if (! $target instanceof User) {
            return ['user' => null, 'refusal' => Result::notFound('利用者が見つかりません')];
        }

        // 対象がそもそもオーナーでない（または無効）なら、オーナー数は減らない
        if (! self::isFull($target->role()) || ! $target->is_active) {
            return ['user' => $target, 'refusal' => null];
        }

        foreach ($locked as $id => $row) {
            if ($id !== $targetId && self::isFull($row->role()) && $row->is_active) {
                return ['user' => $target, 'refusal' => null];
            }
        }

        return ['user' => $target, 'refusal' => Result::conflict($kind === 'role'
            ? '最後のオーナーは降格できません。先に別のオーナーを追加してください'
            : '最後のオーナーは無効化できません。先に別のオーナーを追加してください')];
    }

    /**
     * 候補（有効なオーナー全員 + 対象）を **ID 昇順**で 1 行ずつロックする。
     *
     * 走査でまとめてロックさせない（索引の有無で順序が変わるため）。
     * 件数はオーナーの人数 + 1 なので、行数に比例して重くはならない。
     *
     * @return array<int, User> 主キー => ロック済みの行
     */
    private function lockOwnerSet(int $targetId): array
    {
        /** @var list<int> $ids */
        $ids = User::query()
            ->whereIn('role', [Role::Owner->value, Role::Developer->value])
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $ids[] = $targetId;
        $ids = array_values(array_unique($ids));
        sort($ids);

        $locked = [];

        foreach ($ids as $id) {
            $row = User::query()->whereKey($id)->lockForUpdate()->first();

            if ($row instanceof User) {
                $locked[$id] = $row;
            }
        }

        return $locked;
    }

    public function isSelf(User $user, OperationContext $context): bool
    {
        return $context->actorId !== null && (int) $user->getKey() === $context->actorId;
    }

    /** 無効化してよいか。だめなら理由つきの Result を返す */
    public function refuseDeactivation(User $user, OperationContext $context): ?Result
    {
        if (! $user->is_active) {
            return Result::conflict('この利用者はすでに無効化されています');
        }

        if ($this->isSelf($user, $context)) {
            return Result::conflict('自分自身を無効化することはできません');
        }

        if ($this->isLastActiveOwner($user)) {
            return Result::conflict('最後のオーナーは無効化できません。先に別のオーナーを追加してください');
        }

        return null;
    }

    /** ロールを変更してよいか。だめなら理由つきの Result を返す */
    public function refuseRoleChange(User $user, Role $next, OperationContext $context): ?Result
    {
        if ($this->isSelf($user, $context)) {
            return Result::conflict('自分自身のロールは変更できません。別のオーナーに依頼してください');
        }

        if ($user->role() === $next) {
            return Result::conflict('すでに同じ権限です');
        }

        if (! self::isFull($next) && $this->isLastActiveOwner($user)) {
            return Result::conflict('最後のオーナーは降格できません。先に別のオーナーを追加してください');
        }

        return null;
    }

    /**
     * 画面へ渡す「この利用者に対してできること」。
     *
     * @return array<string, bool>
     */
    public function abilitiesFor(User $user, OperationContext $context): array
    {
        return [
            'canDeactivate' => $this->refuseDeactivation($user, $context) === null,
            'canReactivate' => ! $user->is_active,
            'canChangeRole' => ! $this->isSelf($user, $context) && ! $this->isLastActiveOwner($user),
            'isSelf' => $this->isSelf($user, $context),
            'isLastActiveOwner' => $this->isLastActiveOwner($user),
        ];
    }
}
