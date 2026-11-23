<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Settings\SettingsStore;
use Carbon\CarbonImmutable;

/** 実際に実行した結果だけを DB に残す。キャッシュを捨てても再起動しても記録は残る。 */
final readonly class ReadinessResult
{
    public function __construct(private SettingsStore $settings) {}

    /**
     * 見せる日時は日本時間（直し 4 回目）。
     *
     * `now()` は `APP_TIMEZONE` に従う。サーバー用の `.agentcms/deploy/env.*.example` には
     * 直し 5 回目で `APP_TIMEZONE=Asia/Tokyo` を足したが、それ以前に置いた `shared/.env` や
     * 人が消したサーバーでは Laravel の既定 UTC のまま動き、「11月23日 10:28」のように 9 時間前に出る。
     * 記録は時間帯付きの ISO8601（機械の値）だけを正にし、人が読む文字は読むたびに日本時間で作る
     * — 以前に UTC で保存された `label` も、読み直せば正しく出る。
     */
    private const DISPLAY_TIMEZONE = 'Asia/Tokyo';

    public function record(int $count): void
    {
        $at = now();
        $this->settings->put(['readiness_result' => ['count' => $count, 'checkedAt' => $at->toIso8601String(), 'label' => self::label($at->toIso8601String())]]);
    }

    private static function label(string $iso): string
    {
        return CarbonImmutable::parse($iso)->setTimezone(self::DISPLAY_TIMEZONE)->format('n月j日 G:i');
    }

    /** @return array{count: int, checkedAt: string, label: string}|null */
    public function last(): ?array
    {
        $value = $this->settings->get('readiness_result');
        if (! is_array($value) || ! is_int($value['count'] ?? null) || $value['count'] < 0 || ! is_string($value['checkedAt'] ?? null) || ! is_string($value['label'] ?? null)) {
            return null;
        }

        try {
            $label = self::label($value['checkedAt']);
        } catch (\Throwable) {
            return null; // 読めない日時の記録は「回していない」と同じに扱う（緑にしない）
        }

        return ['count' => $value['count'], 'checkedAt' => $value['checkedAt'], 'label' => $label];
    }

    public function doneMessage(): ?string
    {
        $last = $this->last();

        return $last !== null && $last['count'] === 0
            ? '最初の 6 歩が済みました（公開前チェック '.$last['label'].'・直すもの 0 件）。次は、サーバーに出してインターネットで見られるようにします。'
            : null;
    }
}
