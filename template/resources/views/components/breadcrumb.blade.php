{{-- component: public.breadcrumb（P1 ⑤・`PHASE9_SPEC.md` §J / §H）

     いまどこに居るかを示す道。**祖先はメニューの木から、末端はページから**。

     `PHASE9_9_SPEC.md` §A.2 が「パンくずはメニューの木とは**別の導出物**」と
     書いているとおり、木だけでは足りない。記事詳細はメニューに載らない
     （載せる意味が無い）が、**パンくずが一番要るのはそこ**である。

     ## 末端は必ず呼ぶ側が渡す

     木に載っているページでも、**末端は渡してもらう**。渡さないと、
     そのページがメニューから外れた日に**パンくずごと消える** — 消えたことは
     誰にも見えない。**道の終わりは、そのページ自身が知っている。**

     ## Home だけは木に無くても足す

     パンくずが答える問いは「**いまどこに居るか**」で、道の起点はサイトの根である。
     メニューに Home が無くても先頭に置く。**木に無いものを足すのは、これ 1 つだけ。**

     ## 構造化データ

     `BreadcrumbList` を JSON-LD で出す（`PHASE9_SPEC.md` §104 で MUST）。
     `type="application/ld+json"` は**実行されないデータブロック**なので、
     CSP の `script-src` には当たらない（nonce は要らない）。 --}}
@props(['menu' => 'header', 'current' => null])

@php
    $trail = [
        ['title' => 'ホーム', 'url' => route('home')],
        ...app(\AgentCms\Core\Menus\MenuTree::class)->trailFor($menu, request()->path()),
    ];

    // 末端（いまいるページ）は呼ぶ側が渡す。木には載っていない
    if (is_string($current) && $current !== '') {
        $trail[] = ['title' => $current, 'url' => null];
    }

    // 根だけなら道にならない。**ホームでパンくずを出さない**
    $show = count($trail) > 1;
@endphp

@if ($show)
    <nav class="breadcrumb" aria-label="パンくず">
        <ol>
            @foreach ($trail as $index => $step)
                <li @if ($loop->last) aria-current="page" @endif>
                    @if ($step['url'] !== null && ! $loop->last)
                        <a href="{{ $step['url'] }}">{{ $step['title'] }}</a>
                    @else
                        {{ $step['title'] }}
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    <script type="application/ld+json">{!! json_encode([
        {{-- **`'@context'` と直に書かない。** Blade が `@context` を
             ディレクティブとして食い、**キーが PHP のソースに化ける**。
             壊れていることは JSON を見るまで分からない --}}
        '@'.'context' => 'https://schema.org',
        '@'.'type' => 'BreadcrumbList',
        'itemListElement' => array_values(array_map(
            static fn (int $index, array $step): array => array_filter([
                '@'.'type' => 'ListItem',
                'position' => $index + 1,
                'name' => $step['title'],
                'item' => $step['url'] === null ? null : url($step['url']),
            ], static fn (mixed $value): bool => $value !== null),
            array_keys($trail),
            $trail,
        )),
    {{-- **`JSON_UNESCAPED_SLASHES` を付けない**（付けると `</script>` がそのまま出る）。
         さらに **`JSON_HEX_TAG` で `<` `>` 自体を潰す** — 特定の綴りを見張るのではなく、
         **脱出に使える文字を残さない**。CSP は最後の砦であって、ここで塞ぐのが先である --}}
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
