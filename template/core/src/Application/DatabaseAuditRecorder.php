<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;

/**
 * 監査ログの既定実装（Phase 9.1B / STEP 8）。
 *
 * Eloquent モデルを増やさず、`audit_logs` へ直接書く（読み出しは将来の管理画面で追加）。
 * **READ は記録しない**。metadata は呼び出し側が安全な値だけを渡す前提だが、
 * ここでも既知の危険キーを落とす二重の防御を入れる。
 */
final class DatabaseAuditRecorder implements AuditRecorder
{
    /**
     * IP と User-Agent を残す operation の接頭辞。
     *
     * **認証まわりのセキュリティイベントだけ**に限る（STAGE #18A）。
     * 通常のコンテンツ操作にまで付けると、監査ログが恒常的な
     * 個人情報の入れ物になってしまう。
     */
    private const NETWORK_CONTEXT_PREFIX = 'auth.';

    /** metadata に入っていても保存しないキー（部分一致） */
    private const FORBIDDEN_KEYS = [
        'password', 'token', 'secret', 'authorization', 'cookie',
        'api_key', 'apikey', 'credential', 'body', 'message',
    ];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function record(
        string $operationId,
        OperationClassification $classification,
        OperationContext $context,
        Result $result,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
    ): void {
        if (! $classification->shouldAudit()) {
            return;
        }

        $this->connection->table('audit_logs')->insert([
            'actor_type' => $context->actorType->value,
            'actor_id' => $context->actorId,
            'actor_label' => mb_substr($context->actorLabel, 0, 120),
            'interface' => $context->interface->value,
            'operation' => $operationId,
            'classification' => $classification->value,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId !== null ? mb_substr($resourceId, 0, 80) : null,
            'result' => $result->status->value,
            'confirmation_token' => $this->confirmationFingerprint(
                // 確認待ちで返した行には context の token がまだ無い。
                // その場合は **これから相手へ渡す token** を指紋にして、
                // あとで来る成功の行と同じ値で結べるようにする（Correction 2-4）
                $context->confirmationToken ?? $result->confirmation?->token,
            ),
            'metadata' => json_encode($this->safe($metadata), JSON_UNESCAPED_UNICODE),
            'request_id' => $context->requestId,
            ...$this->networkContext($operationId),
            'created_at' => now(),
        ]);
    }

    /**
     * 確認トークンの **不可逆な指紋**（Correction 8 / 2-4）。
     *
     * 生のトークンを履歴へ残すと、閲覧できる人が
     * **未使用の確認を横取りできる**（トークンは 1 回で使い切るが、
     * 使われる前にログを読めれば成立してしまう）。
     * 「同じ確認に紐づく操作か」を追えれば十分なので、
     * 復元できない短い指紋だけを残す。
     *
     * 発行側（confirmation_required）と使用側（success）の **両方**が
     * 同じ指紋になるので、確認から適用までを 1 本の線として追える。
     * 指紋は sha256 の先頭 32 文字で、元のトークンへは戻せない。
     */
    private function confirmationFingerprint(?string $token): ?string
    {
        return $token === null || $token === ''
            ? null
            : substr(hash('sha256', $token), 0, 32);
    }

    /**
     * 認証イベントに限って記録する接続元情報。
     *
     * Request は **その都度解決する**（singleton が最初のリクエストを
     * 抱え込まないようにするため）。CLI 実行では両方 null になる。
     *
     * @return array<string, string|null>
     */
    private function networkContext(string $operationId): array
    {
        if (! str_starts_with($operationId, self::NETWORK_CONTEXT_PREFIX)) {
            return [];
        }

        $request = app()->bound('request') ? app('request') : null;

        // 認証イベントは HTTP からしか起きない。CLI では request が
        // 合成物なので、接続元が取れない場合は何も足さない
        if (! $request instanceof Request || $request->ip() === null) {
            return [];
        }

        $agent = $request->userAgent();

        return [
            'ip_address' => $request->ip(),
            // 長さの上限を持たせる（際限なく保存しない）
            'user_agent' => is_string($agent) ? mb_substr($agent, 0, 255) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safe(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            $lower = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                if (str_contains($lower, $forbidden)) {
                    continue 2;
                }
            }

            // 連想配列は **値を保存せずキー名だけ**に畳む（値に機微が入り得るため）。
            // フィールド名の一覧のような list はそのまま残す（監査の役に立つ情報）
            if (is_array($value)) {
                $safe[$key] = array_is_list($value) ? $value : array_keys($value);

                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }
}
