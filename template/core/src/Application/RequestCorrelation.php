<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

use Illuminate\Support\Str;

/**
 * 1 リクエスト = 1 個の相関 ID（STAGE #18A / Correction 2-3）。
 *
 * ## なぜ要るのか
 *
 * 監査ログは「何が起きたか」を 1 行ずつ残すが、**どの試行の話か**が分からないと
 * 追えない。確認待ちで返した行と、そのあと確認トークン付きで成功した行は
 * *別の試行*であり、逆に 1 回の HTTP リクエストで 2 つの operation が動いたなら
 * それは *同じ試行*である。相関 ID はその区別だけを担う。
 *
 * ## 外から渡された ID を信用しない
 *
 * `X-Request-ID` のような受信ヘッダーをそのまま採用すると、
 * 攻撃者が **他人の試行と同じ ID を名乗って** 監査を混ぜられる。
 * ここで採番するのは常にサーバー側の ULID であり、
 * 受信ヘッダーは読まない（`AssignRequestId` も読まない）。
 *
 * ## 生存期間
 *
 * container には singleton で入れ、HTTP は `AssignRequestId` が
 * リクエストの先頭で必ず振り直す。CLI / queue / MCP では
 * 最初に問い合わせた時点で 1 個だけ遅延生成し、そのプロセス実行中は同じ値を返す。
 */
final class RequestCorrelation
{
    private ?string $id = null;

    /** リクエストの先頭で新しい ID を振る（HTTP は必ずここを通る） */
    public function assign(?string $id = null): string
    {
        return $this->id = $id ?? self::generate();
    }

    /** この実行単位の相関 ID。まだ無ければ 1 個だけ作る */
    public function id(): string
    {
        return $this->id ??= self::generate();
    }

    private static function generate(): string
    {
        return (string) Str::ulid();
    }
}
