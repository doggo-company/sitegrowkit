<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * Application Service の戻り値（Phase 9.1B / STEP 1）。
 *
 * Admin / API / MCP は **この 1 つの型**を各 interface へ変換するだけにする。
 * adapter ごとに成功・失敗の解釈を作らない。
 */
final readonly class Result
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public OperationStatus $status,
        public ?array $data = null,
        public array $errors = [],
        public ?string $message = null,
        public ?Confirmation $confirmation = null,
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array<string, mixed>  $meta
     */
    public static function success(?array $data = null, array $meta = []): self
    {
        return new self(OperationStatus::Success, data: $data, meta: $meta);
    }

    /** @param  array<string, list<string>>  $errors */
    public static function validationError(array $errors, ?string $message = null): self
    {
        return new self(OperationStatus::ValidationError, errors: $errors, message: $message);
    }

    public static function notFound(?string $message = null): self
    {
        return new self(OperationStatus::NotFound, message: $message);
    }

    public static function forbidden(?string $message = null): self
    {
        return new self(OperationStatus::Forbidden, message: $message);
    }

    /** @param  array<string, mixed>  $data */
    public static function conflict(?string $message = null, array $data = []): self
    {
        return new self(OperationStatus::Conflict, data: $data, message: $message);
    }

    /**
     * @param  array<string, mixed>|null  $data  確認画面が「何が起きるか」を出すための内訳。
     *                                           省略時は従来どおり confirmation だけを返す
     */
    public static function confirmationRequired(Confirmation $confirmation, ?array $data = null): self
    {
        return new self(OperationStatus::ConfirmationRequired, data: $data, confirmation: $confirmation);
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    /**
     * meta へ情報を足した写しを返す（結末そのものは変えない）。
     *
     * 「操作は成功したが監査だけ落ちた」のように、
     * **結末を書き換えずに事実を 1 つ添えたい**ときに使う。
     *
     * @param  array<string, mixed>  $extra
     */
    public function withMeta(array $extra): self
    {
        return new self(
            $this->status,
            $this->data,
            $this->errors,
            $this->message,
            $this->confirmation,
            [...$this->meta, ...$extra],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status->value,
            'data' => $this->data,
            'errors' => $this->errors === [] ? null : $this->errors,
            'message' => $this->message,
            'confirmation' => $this->confirmation?->toArray(),
            'meta' => $this->meta === [] ? null : $this->meta,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
