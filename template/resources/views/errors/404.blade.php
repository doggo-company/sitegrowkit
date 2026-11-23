{{-- 公開サイトの 404（直し3回目）。サイトの枠（見本・取り込んだテーマ）に載せるときは、
     記事の一覧と同じく部品の CSS（.container の幅と余白）を足す。足さないと本文が左端に貼り付いた --}}
@if (($layout ?? 'layouts.public') !== 'layouts.public')
    @push('head')
        @vite('resources/css/site-parts.css')
    @endpush
@endif
@extends($layout ?? 'layouts.public')

@section('content')
<section class="container" style="padding-block:4rem">
    <h1>ページが見つかりません</h1>
    <p>ページが移動したか、公開を終えた可能性があります。</p>
    <p><a href="{{ url('/') }}">トップへ戻る</a></p>
    @if(app(\App\Support\BundledRouteAvailability::class)->reason('contact.show') === null)
        <p><a href="{{ route('contact.show') }}">お問い合わせ</a></p>
    @endif
</section>
@endsection
