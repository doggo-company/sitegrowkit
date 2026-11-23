<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\ChecksViews;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * フォームの公開画面が載る layout（R-12 / 受付の一本化）。
 *
 * ## なぜ 1 か所に寄せるのか
 *
 * 入力画面は `PublicFormController::inputView()` が「案件の意匠が在ればそれ」で選ぶ。
 * ところが**完了画面と確認画面は `layouts.public` を直書きしていた**ので、
 * **送った瞬間に意匠が変わる**（2026-08-30 に実測。同梱見本で送信すると、
 * ヘッダーの項目名まで `About/Service/Contact` から `私たちについて/サービス/…` に変わる）。
 *
 * 入口と出口で違う layout に載るのは、**押した人から見れば別のサイトへ飛んだのと同じ**である。
 *
 * ## 選び方
 *
 * **入力画面が意匠の view で描かれているなら、出口も同じ意匠に載せる。**
 * 判定材料は入力画面と同じ `forms.imported` の有無で、ここで新しい条件を作らない。
 *
 * ## 題名の作り方が layout ごとに違う
 *
 * `layouts.public` は `"{$title} | {サイト名}"` を**自分で組む**が、
 * `layouts.imported` は渡された `$title` を**そのまま出す**。
 * 同じ文字列を渡すと、片方でサイト名が二重になるか、片方で消える。
 * **その差をここで吸収する** — view 側に `@if` を増やさない。
 */
final class PublicFormLayout
{
    use ChecksViews;

    private const IMPORTED_LAYOUT = 'layouts.imported';

    public function __construct(private readonly ViewFactory $views) {}

    /**
     * 載せる layout の名前。
     *
     * 見本の意匠をやめたとき（または意匠の view が無いとき）も、店の枠があればそれに載せる
     * （UX 一周 2026-09-28: kit 既定の素朴な枠に落ちて、そこだけ別のサイトに見えた）。
     */
    public function name(): string
    {
        return $this->usesImported() ? self::IMPORTED_LAYOUT : app(SiteFrame::class)->layout();
    }

    /**
     * その layout に渡す `$title`。
     *
     * @param  string  $heading  そのページの見出し（「お問い合わせ（送信完了）」など）
     */
    public function title(string $heading, string $siteName): string
    {
        // 店の枠（layouts.imported）は題をそのまま出す。kit 既定の枠は自分でサイト名を足す
        return $this->name() === self::IMPORTED_LAYOUT
            ? $siteName.' | '.$heading
            : $heading;
    }

    /**
     * 入力画面が案件の意匠で描かれているか。
     *
     * `ContactEntry::IMPORTED_VIEW` が在るかどうかだけを見る
     * （`PublicFormController::inputView()` と同じ材料）。
     * **同梱見本かどうかは区別しない** — 見本サイトなら見本の意匠が正しいからである。
     */
    private function usesImported(): bool
    {
        // 入力画面と同じ答えにする（見本の意匠を外したなら、確認・完了も外す）
        return app(ContactEntry::class)->rendersImportedView()
            && $this->viewExists(self::IMPORTED_LAYOUT);
    }
}
