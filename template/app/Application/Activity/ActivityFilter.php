<?php

declare(strict_types=1);

namespace App\Application\Activity;

use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Audit\AuditLog;
use App\Support\OperationCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 行動履歴の絞り込み条件（PHASE WP-BASELINE / STAGE #18A）。
 *
 * **入力は allowlist で正規化する。** 期間・利用者・操作・結果・経路・対象は
 * どれも決まった集合しか受け付けないので、任意の値がクエリへ入らない。
 * 一覧と CSV は同じ条件を使う（画面と書き出しがずれない）。
 */
final readonly class ActivityFilter
{
    private function __construct(
        public ?int $actorId,
        public ?string $operation,
        public ?string $result,
        public ?string $interface,
        public ?string $resourceType,
        public ?Carbon $from,
        public ?Carbon $until,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromInput(array $input, ?int $actorId = null): self
    {
        return new self(
            actorId: $actorId ?? self::intOrNull($input['actorId'] ?? null),
            operation: self::allowed($input['operation'] ?? null, self::operations()),
            result: self::allowed($input['result'] ?? null, array_column(OperationStatus::cases(), 'value')),
            interface: self::allowed($input['interface'] ?? null, array_column(OperationInterface::cases(), 'value')),
            resourceType: self::text($input['resourceType'] ?? null, 80),
            from: self::date($input['from'] ?? null)?->startOfDay(),
            until: self::date($input['until'] ?? null)?->endOfDay(),
        );
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->actorId !== null, fn (Builder $q) => $q->where('actor_id', $this->actorId))
            ->when($this->operation !== null, fn (Builder $q) => $q->where('operation', $this->operation))
            ->when($this->result !== null, fn (Builder $q) => $q->where('result', $this->result))
            ->when($this->interface !== null, fn (Builder $q) => $q->where('interface', $this->interface))
            ->when($this->resourceType !== null, fn (Builder $q) => $q->where('resource_type', $this->resourceType))
            ->when($this->from !== null, fn (Builder $q) => $q->where('created_at', '>=', $this->from))
            ->when($this->until !== null, fn (Builder $q) => $q->where('created_at', '<=', $this->until));
    }

    /** @return array<string, mixed> 画面へ返す現在の条件（そのまま form に戻せる形） */
    public function toArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'operation' => $this->operation,
            'result' => $this->result,
            'interface' => $this->interface,
            'resourceType' => $this->resourceType,
            'from' => $this->from?->toDateString(),
            'until' => $this->until?->toDateString(),
        ];
    }

    /**
     * 絞り込みに出す operation の候補。
     *
     * 権限表に載っている操作 + 認証イベント。**履歴に出ない値を選ばせない**。
     *
     * @return list<string>
     */
    public static function operations(): array
    {
        return [...OperationCapabilities::operationIds(), ...ActivityLabels::authOperations()];
    }

    /** @param  list<string>  $allowed */
    private static function allowed(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // 資源の種別は識別子。自由文は受け付けない
        return preg_match('/\A[a-z0-9_.\-]{1,'.$max.'}\z/i', $value) === 1 ? $value : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
