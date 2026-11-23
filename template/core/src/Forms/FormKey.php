<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

/**
 * フォームの識別子の規則（R-11 ① / 監査 指摘 41・42）。
 *
 * ## なぜ 1 か所に置くのか
 *
 * 同じ規則が**画面（下書き）と保存（確定）の 2 か所**に在ったせいで、
 * **同じ入力が別の値になった**。
 *
 * - `C`（1 文字）… 手で打つと「2〜64 文字」で断られるのに、
 *   名前から作ると通って `/forms/c` が配られていた
 * - `"ab " × 30` … 60 文字で切った端にハイフンが残り、`/forms/ab-…-ab-` になっていた
 *
 * **識別子はあとから変えられない**ので、ずれた分がそのまま配られる。
 *
 * ここは規則の**正本**である。画面側（`resources/js/lib/form-key.ts`）は同じ規則を
 * TypeScript で持つが、**同じ表**（`resources/js/lib/__tests__/form-key-cases.json`）で
 * 両方を突き合わせている。片方だけ触ると、どちらかの試験が落ちる。
 *
 * ## 式が 2 か所に在るのは、**意図して残した**（2026-08-22 の裁定）
 *
 * PHP と TypeScript が同じ式を持つのを、生成（PHP → JSON → TS）で 1 本にはしない。
 * **生成物は「作り直し忘れ」という新しい失敗の形を持ち込む**からである。
 *
 * 2 か所に在ること自体が害なのではなく、**ずれたときに気づけないこと**が害である。
 * 気づく仕組み（上の表）が在るので、これで足りると決めた。
 * **「まだ直していない」ではなく「表で縛ると決めた」。**
 */
final class FormKey
{
    /** 使える形。`SaveForm` の検査と**同じ式**である（緩い方を別に持たない） */
    public const PATTERN = '/^[a-z][a-z0-9_-]{1,63}$/';

    /** 名前から作る slug の長さ（連番を足す余地を残す） */
    public const MAX_LENGTH = 60;

    /** 識別子そのものの上限（`forms.key` の桁 = `PATTERN` の上限） */
    public const KEY_MAX = 64;

    /**
     * 名前から識別子を作る。**作れなければ空**（推測で埋めない）。
     *
     * 日本語からは 1 文字も取れない — **無理に音訳しない**
     * （読み方を推測すると、利用者の意図と違う URL が配られる）。
     */
    public static function slugify(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, self::MAX_LENGTH);

        // **切った端の区切りを残さない。** `/forms/ab-…-ab-` が配られてしまう
        $slug = trim($slug, '-');

        return preg_match(self::PATTERN, $slug) === 1 ? $slug : '';
    }

    /**
     * 末尾に連番などを足す（**上限を超えない**）。
     *
     * 足すぶんだけ base を先に削る。削らずに繋ぐと、
     * base が 60 文字のときに **67 文字**（`60 + "-" + 6 桁`）になり、
     * 規則にも `forms.key` の桁にも収まらない。
     * SQLite は黙って保存するが、**MySQL では例外になる**
     * （利用者には「その識別子のフォームが既にあります」と、実態と違う顔で出る）。
     *
     * 削った端に区切りが残らないようにするのは `slugify()` と同じ理由である。
     */
    public static function suffixed(string $base, string $suffix): string
    {
        $room = self::KEY_MAX - mb_strlen($suffix) - 1;
        $head = rtrim(mb_substr($base, 0, max($room, 1)), '-');

        return $head.'-'.$suffix;
    }
}
