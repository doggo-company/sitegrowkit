{{-- component: public.assigned-terms（R05・2026-09-09）

     記事に付いている分類を訪問者に見せる。汎用の詳細と `news` / `works` の詳細で
     **同じ 1 枚**を使う — 3 か所に同じ markup を書くと、片方だけ育つ。

     渡すのは `App\Support\TermArchiveLinks::forEntry()` の結果。
     `url` が null の項目は**文字のまま**出す（一覧を持たない型・公開されていない型では
     押せる先が無い。無理にリンクして 404 を配らない）。

     **付いていなければ 1 バイトも出さない。** 空の見出しは
     「分類が無い」ではなく「壊れている」に見える。
     名前は人が入れるので `{{ }}` で必ずエスケープする。 --}}
@props(['taxonomies' => []])

@if ($taxonomies !== [])
    <div class="article__taxonomies">
        @foreach ($taxonomies as $taxonomy)
            <p class="article__taxonomy">
                <span class="article__taxonomy-label">{{ $taxonomy['label'] }}</span>
                @foreach ($taxonomy['terms'] as $term)
                    @if (($term['url'] ?? null) !== null)
                        <a class="article__term" href="{{ $term['url'] }}">{{ $term['name'] }}</a>
                    @else
                        <span class="article__term">{{ $term['name'] }}</span>
                    @endif
                @endforeach
            </p>
        @endforeach
    </div>
@endif
