{{-- component: public.navigation (Phase 9.9 / STAGE 22C・SPEC §D.1)
     CMS のデータからサイトの導線を描く。

     **要素・class・aria-label は呼び出し側が決める。** 取り込み済みテーマの CSS は
     `.site-nav a` のような形で当たっているので、ここで勝手に包むと見た目が壊れる。
     項目が 1 つも無ければ <nav> ごと出さない（空の landmark を置かない）。

     URL は保存されていない。`MenuTree` がそのとき作る（SPEC §B.3）。
     出せない項目（ゴミ箱・下書き・route が無い）は `MenuTree` が既に落としている。 --}}
@props(['menu', 'fallback' => false])

@php
    $items = app(\AgentCms\Core\Menus\MenuTree::class)->forKey($menu, request()->path());

    /*
     * 項目が 1 つも無いときに、**既定の導線へ落ちる**（B-28）。
     *
     * 判定は上の 1 行だけ。**2 つ目の判定を作らない** — 作ると
     * 「component は出さないが逃げ道も出ない」窓が開く。
     *
     * 出す項目は `App\Support\DefaultNavigation`（seeder と**同じ宣言**）。
     * ここで組み立て直すと、順序と表示名の正本が 2 か所になる。
     *
     * `fallback` を渡すのは**サイトの主要な導線だけ**（いまは header）。
     * フッターまで勝手に生やすと、意図して空にした本が復活してしまう。
     */
    $defaults = $items === [] && $fallback ? \App\Support\DefaultNavigation::links() : [];
@endphp

@if ($items === [] && $defaults !== [])
    {{-- 既定の導線。**訪問者にナビが無い状態を公開しない**（運用者には設定画面で知らせる） --}}
    <nav {{ $attributes }} data-default-navigation="true">
        @foreach ($defaults as $item)
            <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>
        @endforeach
    </nav>
@endif

@if ($items !== [])
    {{-- class も aria-label も**呼び出し側が書いた順のまま**出す --}}
    <nav {{ $attributes }}>
        @foreach ($items as $item)
            <a
                href="{{ $item['url'] }}"
                @if ($item['target'] !== '_self') target="{{ $item['target'] }}" @endif
                @if ($item['rel']) rel="{{ $item['rel'] }}" @endif
                @if ($item['current']) aria-current="page" @endif
            >{{ $item['title'] }}</a>

            @if ($item['children'] !== [])
                <ul class="nav-children">
                    @foreach ($item['children'] as $child)
                        <li>
                            <a
                                href="{{ $child['url'] }}"
                                @if ($child['target'] !== '_self') target="{{ $child['target'] }}" @endif
                                @if ($child['rel']) rel="{{ $child['rel'] }}" @endif
                                @if ($child['current']) aria-current="page" @endif
                            >{{ $child['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    </nav>
@endif
