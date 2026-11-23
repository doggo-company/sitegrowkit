<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

/**
 * 何に対する書き込みか（Phase 9.13 / STAGE 26B・SPEC §F.1）。
 *
 * ## 判定の側が「フォーム」を知らないようにする
 *
 * 9.12 の実装は `Form` に結び付いていた。コメント（P0-9）も同じ対策を要るので、
 * **別実装を作ると同じ対策が 2 つになる**。片方だけ直す事故が起きるので、
 * 判定は書き込み口の種類を知らない形にして、両方から使う。
 *
 * ## 上限は呼ぶ側が持ってくる
 *
 * 1 分あたりの上限は書き込み口ごとに違う（フォームは 1 本ずつ、コメントは
 * サイト全体で荒れる）。判定の中で config のキーを組み立てると、
 * **キー名の綴りを間違えても静かに既定値で動く**。だから呼ぶ側が読んで渡す。
 */
final class SpamSubject
{
    /**
     * @param  string  $kind  集計の軸（`form` / `comment`）
     * @param  int|string  $id  集計と rate limit の対象。**同じ相手が連投できる範囲**を決める
     * @param  int  $ratePerMinute  1 分あたりの上限。0 以下で無効
     */
    public function __construct(
        public readonly string $kind,
        public readonly int|string $id,
        public readonly int $ratePerMinute = 0,
    ) {}

    /** 集計表に入れる鍵（`subject_id` は文字列で持つ — 型ごとに桁も種類も違う） */
    public function subjectId(): string
    {
        return (string) $this->id;
    }
}
