<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use InvalidArgumentException;

/**
 * 台帳を引くときの鍵（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 本文を持てない形にする
 *
 * ここへ入るのは種類・取り込み先・外部 id だけで、**いずれも識別子の形しか通さない**。
 * 通してしまうと、台帳を引くだけの経路が本文や名前やメールアドレスを運ぶ。
 * その値は問い合わせのログにも、この先の診断にも残る。
 *
 * 形が違うものは**数えずに断る**。WXR の中身が悪いのなら読む側で数えるべきで、
 * ここまで来ている時点で組み立ての誤りである。
 *
 * ## 桁は台帳に合わせる
 *
 * `import_records.target_type` と `external_id` は varchar(64) である。
 * 超える値で引くと、DB が黙って切って**別の行に当たる**ことがある。
 */
final class LedgerKey
{
    /** `import_records` の桁 */
    public const MAX_BYTES = 64;

    /**
     * 取り込み先の名前。投稿タイプ id / 分類 key / `comment` / `media`。
     *
     * 空白も記号も入らない。`post type` のような値はここで断る。
     */
    private const TARGET_TYPE_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

    /**
     * WXR 側の id。数字であることがほとんどだが、
     * 台帳の桁に収まる識別子の形までは許す（`:` を含む合成 id を将来使えるように）。
     */
    private const EXTERNAL_ID_PATTERN = '/^[A-Za-z0-9_.\-:]{1,64}$/';

    public function __construct(
        public readonly ImportKind $kind,
        public readonly string $targetType,
        public readonly string $externalId,
    ) {
        self::assertTargetType($targetType);
        self::assertShape($externalId, self::EXTERNAL_ID_PATTERN, 'external_id');
    }

    /**
     * 同じ鍵かどうかを見分ける値。
     *
     * 取り込み先まで含める。`(kind, external_id)` だけで畳むと、
     * **対応先を変えた再実行を「同じ鍵」として潰してしまう** —
     * それこそがここで見つけたい食い違いである。
     */
    public function signature(): string
    {
        return $this->kind->value."\0".$this->targetType."\0".$this->externalId;
    }

    /** 種類と外部 id だけの鍵。**取り込み先を跨いで**引くときに使う */
    public function lookupSignature(): string
    {
        return $this->kind->value."\0".$this->externalId;
    }

    /** 取り込み先の名前の形。**`TargetRef` と同じ規則を使う**（2 つ持たない） */
    public static function assertTargetType(string $value): void
    {
        self::assertShape($value, self::TARGET_TYPE_PATTERN, 'target_type');
    }

    /**
     * 台帳から読んだ値にも同じ形を求める。
     *
     * 台帳の行は他から書かれ得る。読んだものをそのまま外へ渡すと、
     * **台帳が本文を運ぶ経路**になる。
     */
    public static function assertStoredIdentifier(string $value, string $column): void
    {
        self::assertShape($value, self::EXTERNAL_ID_PATTERN, $column);
    }

    private static function assertShape(string $value, string $pattern, string $column): void
    {
        if (preg_match($pattern, $value) !== 1) {
            /*
             * **値そのものを出さない。** 断る理由は形であって中身ではなく、
             * ここで echo すると本文がそのまま例外の文面に載る。
             */
            throw new InvalidArgumentException(
                "台帳の {$column} には識別子の形だけを入れてください"
                .'（半角の英数字と `_` `.` `-` `:`、'.self::MAX_BYTES.' byte まで）。',
            );
        }
    }
}
