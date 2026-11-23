<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * view が在るかを実行時に見る。
 *
 * 🔴 **直接 `view()->exists('literal')` と書かない。**
 * larastan が解析時点のファイル有無で `always false` と解決してしまう
 * （実際にそれで 1 度落ちた）。取り込みで後から生える view は、
 * **解析時には無くて当たり前**である。
 *
 * 🔴 **`view()` を毎回引かず、注入された Factory を使う。**
 * 引いてしまうと、**試験から「view が無いとき」を作れない**。
 * 2026-08-29 まで 4 か所に写されていて、そのうち 2 か所が `view()` を
 * 引いていたため、**その半分だけ試験が書けなかった**
 * （`readStdin()` が 1 つだけ `StreamableInputInterface` を見ていなかったのと同じ形）。
 *
 * 使う側は `private readonly ViewFactory $views` を持つこと。
 */
trait ChecksViews
{
    /**
     * kit が同梱した見本だけが名乗る目印。
     *
     * 正本は `packages/cli/src/importer/bundled-samples.ts` の `BUNDLED_SAMPLE_MARK`。
     * **同じ文字列を 2 か所が持っている** — 片方だけ変えると、apply 側は見本と認めるのに
     * 実行時は認めない（またはその逆）になる。変えるときは両方。
     */
    private const BUNDLED_SAMPLE_MARK = 'agentcms:bundled-sample';

    private function viewExists(string $name): bool
    {
        /** @var ViewFactory $views */
        $views = $this->views;

        return $views->exists($name);
    }

    /**
     * その view が **kit の同梱見本**か（案件が作ったものではないか）。
     *
     * 「在るか」だけでは足りない場面がある — 同梱見本は**どの案件にも最初から在る**ので、
     * 「この案件が用意したもの」の代わりに数えると、用意していない案件を取り違える
     * （`ContactEntry::hasOwnImportedView()` の実例）。
     *
     * **無い view は見本ではない**（false）。読めない場合も false に倒す —
     * 見本だと決めつけて機能を止めるより、案件のものとして扱うほうが害が小さい。
     */
    private function isBundledSample(string $name): bool
    {
        if (! $this->viewExists($name)) {
            return false;
        }

        /** @var ViewFactory $views */
        $views = $this->views;

        $path = $views->getFinder()->find($name);

        if (! is_file($path)) {
            return false;
        }

        // 目印は先頭のコメントに在る。全文を読まない
        $head = (string) file_get_contents($path, false, null, 0, 512);

        return str_contains($head, self::BUNDLED_SAMPLE_MARK);
    }
}
