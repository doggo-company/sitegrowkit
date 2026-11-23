<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Trash\TrashReadinessUnavailable;
use App\Console\Concerns\SpeaksJson;
use App\Support\OperationCatalog;
use Illuminate\Console\Command;

/**
 * AI エージェント（MCP）から 1 操作を実行する橋渡し（Phase 9.1B / STEP 11）。
 *
 * - 入力は **stdin の JSON**。argv には載せない
 *   （shell escaping / OS 差 / argv 長制限 / プロセス一覧への露出 / injection を避けるため）
 * - 実行できるのは `OperationCatalog` の allowlist にある操作だけ
 * - 出力は **stdout に Result JSON 1 行のみ**。ログ・警告は stderr へ
 */
final class OperationCommand extends Command
{
    use SpeaksJson;

    protected $signature = 'agentcms:operation {operation : 実行する operation ID}';

    protected $description = 'AI エージェント連携用: 許可された操作を 1 つ実行して Result JSON を返す';

    public function handle(OperationCatalog $catalog): int
    {
        $argument = $this->argument('operation');
        $operationId = is_string($argument) ? $argument : '';

        if (! OperationCatalog::allows($operationId)) {
            return $this->emit([
                'status' => 'forbidden',
                'message' => "操作 [{$operationId}] は許可されていません",
                'allowed' => OperationCatalog::ids(),
            ], failure: true);
        }

        $raw = $this->readStdin();
        $input = $raw === '' ? [] : json_decode($raw, true);

        if (! is_array($input)) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => '標準入力の JSON を読み取れませんでした',
            ], failure: true);
        }

        $context = OperationContext::agent(OperationInterface::Mcp, $this->actorLabel($input))
            ->withConfirmationToken($this->confirmationToken($input));

        /*
         * ゴミ箱の対応状況を確かめられなかった（Phase 9.8 / STAGE 21B・SPEC §C.4.1.2）。
         *
         * **空の成功を返さない。** 0 件の一覧を成功として返すと、エージェントは
         * 「投稿が 1 件も無い」と読んで、それを前提に次の判断をする。
         * 読めなかったなら読めなかったと伝える。原因の詳細は渡さない。
         */
        try {
            $result = $catalog->invoke($operationId, $this->arguments_($input), $context);
        } catch (TrashReadinessUnavailable $e) {
            return $this->emit([
                'status' => 'service_unavailable',
                'message' => $e->safeMessage(),
                'contentType' => $e->contentType,
            ], failure: true);
        }

        return $this->emit($result->toArray(), failure: false);
    }

    /** @param array<string, mixed> $input */
    private function actorLabel(array $input): string
    {
        $label = $input['actor'] ?? null;

        return is_string($label) && $label !== '' ? mb_substr($label, 0, 60) : 'ai-agent';
    }

    /** @param array<string, mixed> $input */
    private function confirmationToken(array $input): ?string
    {
        $token = $input['confirmationToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function arguments_(array $input): array
    {
        $args = $input['arguments'] ?? null;

        return is_array($args) ? $args : [];
    }
}
