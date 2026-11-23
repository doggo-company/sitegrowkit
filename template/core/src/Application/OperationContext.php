<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

use Illuminate\Container\Container;

/**
 * 「誰が・どの経路で」操作しているか（Phase 9.1B / STEP 5・8）。
 *
 * Admin Controller / API / MCP のどこから呼ばれても **同じ認可と監査**を通すため、
 * actor を Application 層へ明示的に渡す。
 */
final readonly class OperationContext
{
    public function __construct(
        public ActorType $actorType,
        public OperationInterface $interface,
        public ?int $actorId = null,
        public string $actorLabel = '',
        /** 破壊的操作の確認トークン（未提示なら null） */
        public ?string $confirmationToken = null,
        /** 同一リクエストの相関 ID（監査用） */
        public ?string $requestId = null,
    ) {}

    public static function admin(?int $userId, string $label = ''): self
    {
        return new self(ActorType::User, OperationInterface::Admin, $userId, $label, requestId: self::correlationId());
    }

    /** 同一オリジンの JSON API（セッション認証） */
    public static function api(?int $userId, string $label = ''): self
    {
        return new self(ActorType::User, OperationInterface::Api, $userId, $label, requestId: self::correlationId());
    }

    public static function cli(string $label = 'artisan'): self
    {
        return new self(ActorType::System, OperationInterface::Cli, null, $label, requestId: self::correlationId());
    }

    public static function agent(OperationInterface $interface, string $label = 'ai-agent'): self
    {
        return new self(ActorType::Agent, $interface, null, $label, requestId: self::correlationId());
    }

    /**
     * この実行単位の相関 ID（`RequestCorrelation` が唯一の採番者）。
     *
     * **受信ヘッダーは見ない**（`AssignRequestId` の docblock 参照）。
     * container がまだ無い場面（純粋な unit test 等）では null に落とす。
     */
    private static function correlationId(): ?string
    {
        $container = Container::getInstance();

        if (! $container->bound(RequestCorrelation::class)) {
            return null;
        }

        return $container->make(RequestCorrelation::class)->id();
    }

    public function withConfirmationToken(?string $token): self
    {
        return new self(
            $this->actorType,
            $this->interface,
            $this->actorId,
            $this->actorLabel,
            $token,
            $this->requestId,
        );
    }

    /** 確認トークンを束縛する主体キー（actor が違えば使い回せない） */
    public function actorKey(): string
    {
        return $this->actorType->value.':'.($this->actorId ?? $this->actorLabel);
    }
}
