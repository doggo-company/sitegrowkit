<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use InvalidArgumentException;

/**
 * 判定に効いた**対象側の状態だけ**を持つ（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## なぜ自由な文字列にしないのか
 *
 * ここが `string $state` だと、呼ぶ側は本文でも著者名でもメールアドレスでも詰められる。
 * 引数の名前を固定しても、**何を入れられるか**は変わらない。
 * 1 か所書き間違えれば、他人の個人情報が plan の出力と台帳へ流れ出る。
 *
 * だから**入れられる項目をここで決め打つ**。持てないものは持てない。
 *
 * ## 入れてよいもの
 *
 * `apply` の結果が「利用者の見た plan」と変わるかどうかに効くものだけ。
 * 台帳に記録があるか、対象がどの状態か、こちらで何回更新されたか、
 * どの slug に当たったか。**中身は 1 文字も入らない。**
 */
final class RelevantTargetState
{
    /** slug の長さの上限（DB 側の一般的な上限に合わせた保守的な値） */
    private const SLUG_MAX = 190;

    public function __construct(
        /** 台帳（`import_records`）に記録があるか */
        public readonly bool $hasImportRecord = false,
        /** 対象側の状態（`published` / `draft` / `trash` など） */
        public readonly ?string $targetStatus = null,
        /** 楽観ロックの版。こちらで何回更新されたかを表す */
        public readonly ?int $lockVersion = null,
        /** 最終更新（秒まで正規化した UTC） */
        public readonly ?string $updatedAt = null,
        /** 突き合わせに当たった slug */
        public readonly ?string $matchedSlug = null,
    ) {
        if ($targetStatus !== null && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $targetStatus) !== 1) {
            throw new InvalidArgumentException(
                "targetStatus は状態の名前だけを入れてください（受け取った値: [{$targetStatus}]）。",
            );
        }

        if ($lockVersion !== null && $lockVersion < 0) {
            /*
             * 版は「こちらで何回更新されたか」なので負にならない。
             * 受けると、**あり得ない状態を hash に畳んだ plan** が作れてしまう。
             * 0 は「一度も更新されていない」という正しい状態なので弾かない。
             */
            throw new InvalidArgumentException(
                "lockVersion は 0 以上です（受け取った値: {$lockVersion}）。",
            );
        }

        if ($updatedAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $updatedAt) !== 1) {
            throw new InvalidArgumentException(
                'updatedAt は Y-m-d\TH:i:sZ へ正規化してから渡してください'
                    ."（受け取った値: [{$updatedAt}]）。",
            );
        }

        if ($matchedSlug !== null) {
            /*
             * **改行と制御文字を受けない。** 受けると、ここは自由記述の欄になる。
             * 出力を 1 行 1 件で読む前提も崩れる。
             */
            if (preg_match('/[\p{Cc}\p{Cf}]/u', $matchedSlug) === 1) {
                throw new InvalidArgumentException('matchedSlug に改行や制御文字は入れられません。');
            }

            if ($matchedSlug === '' || mb_strlen($matchedSlug) > self::SLUG_MAX) {
                throw new InvalidArgumentException(
                    'matchedSlug は 1〜'.self::SLUG_MAX.' 文字にしてください。',
                );
            }
        }
    }

    /**
     * 畳むための形。**キーは固定で、増えない。**
     *
     * @return list<string>
     */
    public function canonical(): array
    {
        return [
            $this->hasImportRecord ? '1' : '0',
            $this->targetStatus ?? '',
            $this->lockVersion === null ? '' : (string) $this->lockVersion,
            $this->updatedAt ?? '',
            $this->matchedSlug ?? '',
        ];
    }
}
