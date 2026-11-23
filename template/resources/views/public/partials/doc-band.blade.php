{{-- サイトの枠（見本・取り込んだテーマ）に載せるときの帯。公開ページと編集画面の Canvas が同じものを描く
     （UX 一周 第 16 周）。見出しの段は本文のブロックが持つので、帯の題は見出し（h1）にしない --}}
@if (!empty($band))
    @if ($band['front'])
        <section class="hero doc-band">
            <div class="hero-inner">
                <p class="doc-band__title doc-band__title--hero">{!! nl2br(e($band['title'])) !!}</p>
                @if ($band['sub'] !== '')<p>{{ $band['sub'] }}</p>@endif
            </div>
        </section>
    @else
        <section class="page-title doc-band">
            <div class="page-title-inner">
                <p class="doc-band__title">{{ $band['title'] }}</p>
            </div>
        </section>
    @endif
@endif
