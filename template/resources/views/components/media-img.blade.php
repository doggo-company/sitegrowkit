{{-- component: public.media-img (registry: pub-media-img)
     Media Entity の公開出力。width/height/alt/loading を必ず出し layout shift を防ぐ。

     派生物は **DB に行があるものだけ** <source> に出す（Phase 9.5-D / SPEC §5）。
     配信のたびにファイルの実在を確かめない（IO を増やさない）。
     並びは AVIF → WebP → 原本。ブラウザが対応していなければ <img> の原本へ落ちる。

     同じ MIME の行（同寸 + 幅候補）は **1 つの <source>** にまとめ、幅の昇順に
     `url 640w, url 1280w, url 2400w` の srcset と sizes を出す（レスポンシブ画像 / release-20260909）。
     ブラウザは sizes と DPR から必要な幅を選ぶので、スマホ幅に 2400px を配らない。
     候補が 1 つだけの画像（旧実装で登録されたもの）は従来どおり descriptor なしで出す。

     sizes の既定は 100vw（画面幅いっぱい）。列に並べる場所では呼び出し側が
     `sizes="(min-width: 768px) 33vw, 100vw"` のように渡す。省略しても **大きめを選ぶだけ**で
     小さすぎる画像を選ぶことは無い。

     写し替えが済んでいない古い行のために、`media_variants` に WebP が無いときだけ
     旧 `has_webp` の `.webp` を試す（legacy fallback — SPEC §7.1）。
     **AVIF に legacy は無い**（旧実装は AVIF を作っていない）ので、そこは推測しない。

     出すものが 1 つも無ければ <picture> で包まない。空の <picture> は
     「候補があるように見えて 1 つも無い」という誤解しか生まない。

     ファーストビュー画像には loading="eager" を渡すこと（機械的な lazy 付与の禁止 — Phase 2 仕様）。 --}}
{{-- alt: 渡されれば使う（画像ブロックの代替テキスト・装飾目的の空文字）。null ならメディアの説明（UX 一周 2026-09-28:
     ブロックの代替テキストと「装飾目的」が公開面に反映されていなかった） --}}
@props(['media', 'loading' => 'lazy', 'imgClass' => '', 'sizes' => '100vw', 'alt' => null])

@if ($media)
    @php
        $sources = $media->pictureSources();
        $hasDatabaseWebp = collect($sources)->contains(fn (array $s): bool => $s['format'] === 'webp');
        // 行が 1 つも無いときだけ旧列を見る。両方あれば行が正である
        $legacyWebp = $hasDatabaseWebp ? null : $media->webpUrl();
    @endphp

    @if ($sources || $legacyWebp)
        <picture>
            @foreach ($sources as $source)
                @if ($source['responsive'])
                    <source srcset="{{ $source['srcset'] }}" sizes="{{ $sizes }}" type="{{ $source['mime'] }}">
                @else
                    <source srcset="{{ $source['srcset'] }}" type="{{ $source['mime'] }}">
                @endif
            @endforeach
            @if ($legacyWebp)
                <source srcset="{{ $legacyWebp }}" type="image/webp">
            @endif
            <x-media-img-tag :media="$media" :loading="$loading" :img-class="$imgClass" :alt="$alt" />
        </picture>
    @else
        <x-media-img-tag :media="$media" :loading="$loading" :img-class="$imgClass" :alt="$alt" />
    @endif
@endif
