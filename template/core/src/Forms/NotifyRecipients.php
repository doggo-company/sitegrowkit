<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

/**
 * 通知先メールアドレスの読み書き（R-11 ⑤）。
 *
 * ## なぜ 1 カラムのままか
 *
 * 通知先は**そのフォームの設定の一部**であって、独立して参照される対象ではない。
 * 別テーブルにすると、保存も読み出しも join が要るのに、得られるものが無い。
 *
 * ## なぜ検証を同じ回で入れるのか
 *
 * これまでも利用者は「a@example.test, b@example.test」と**書けてしまった**。
 * 書けるが `Mail::to()` は 1 つのアドレスとして扱うので**必ず失敗する**。
 * 複数対応だけを入れて検証を入れないと、今度は綴り違いが同じ壊れ方をする。
 * **「書けるが届かない」を経由させない。**
 *
 * ## 保存形
 *
 * 画面は 1 行 1 アドレス。保存は**正規化したカンマ区切りの 1 カラム**。
 * 空行と重複は落とす（重複は同じ人に 2 通届くだけで、意味が無い）。
 */
final class NotifyRecipients
{
    /** `forms.notify_to` の桁数（migration と同じ値） */
    public const MAX_LENGTH = 255;

    /**
     * このフォームの通知先（WP 一周 10 周目 2026-09-04）。
     *
     * フォームの設定 → サイト設定の問い合わせ通知先 → 既定、の順に落ちる。
     *
     * 🔴 **2 か所に書かない。** 公開フォームの送信と、管理画面からの再送が
     * 別々の順で宛先を決めると、「送り直したのに違う所へ届いた」が起きる。
     *
     * @return list<string>
     */
    public static function forForm(?string $formNotifyTo, ?string $siteContactEmail): array
    {
        $addresses = self::listOf($formNotifyTo);

        if ($addresses !== []) {
            return $addresses;
        }

        $fallback = trim((string) ($siteContactEmail ?: config('agentcms.mail.admin_to')));

        return self::listOf($fallback);
    }

    /**
     * 入力を分解する。
     *
     * @return array{addresses: list<string>, invalid: list<string>}
     */
    public static function parse(?string $raw): array
    {
        $addresses = [];
        $invalid = [];

        foreach (preg_split('/[\r\n,]+/', (string) $raw) ?: [] as $part) {
            $one = trim($part);

            if ($one === '') {
                continue;
            }

            if (filter_var($one, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $one;

                continue;
            }

            // 同じ宛先を 2 度書いても 2 通は送らない
            if (! in_array($one, $addresses, true)) {
                $addresses[] = $one;
            }
        }

        return ['addresses' => $addresses, 'invalid' => $invalid];
    }

    /** 保存する形（カンマ区切り）。1 件も無ければ null */
    public static function normalize(?string $raw): ?string
    {
        $addresses = self::parse($raw)['addresses'];

        return $addresses === [] ? null : implode(',', $addresses);
    }

    /**
     * 送信に使う宛先の一覧。
     *
     * @return list<string>
     */
    public static function listOf(?string $stored): array
    {
        return self::parse($stored)['addresses'];
    }
}
