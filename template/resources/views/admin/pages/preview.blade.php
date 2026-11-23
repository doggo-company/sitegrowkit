{{--
    下書きのプレビュー（Phase 9.3B / #19・SPEC §Y）。

    公開ページと同じ Blade SSR で描画するが、**描くのは draft version**。
    公開 URL は published version を描く。ここは常に下書きである。

    - 共有 token は無い。auth + verified + OperationPolicy を通った人だけが見る
    - 検索エンジンにもキャッシュにも残さない（noindex / no-store は response header）
    - 公開側と同じく Vue runtime は要求しない
--}}
@extends($layout ?? 'layouts.public')

@section('content')
    @push('head')
        <meta name="robots" content="noindex, nofollow">
        {{-- Design Token は runtime に CSS custom property として出力する（build 不要） --}}
        <style>{!! $themeCss !!}</style>
        @vite('resources/css/document.css')
        <style>
            .preview-notice {
                position: sticky;
                top: 0;
                z-index: 50;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                padding: 0.75rem 1rem;
                background: #1f2937;
                color: #f9fafb;
                font-size: 0.875rem;
                line-height: 1.5;
            }

            .preview-notice a {
                color: #f9fafb;
                text-decoration: underline;
                text-underline-offset: 2px;
            }

            .preview-notice a:focus-visible {
                outline: 2px solid #f9fafb;
                outline-offset: 2px;
            }
        </style>
    @endpush

    {{--
        Canvas（Visual Page Editor）から読むときは案内を**描かない**。
        Editor 側に同じ情報（下書き / 未公開の変更）が常に出ているので二重になり、
        編集面の高さも削ってしまう。**描画そのものは公開と同じ**で、出し分けるのは案内だけ。

        🔴 以前は `hidden` 属性で隠していたが、上の `.preview-notice { display: flex }` が
        `[hidden]` の既定（display: none）に勝ち、**Canvas の中に案内が出ていた**。
        その「編集画面へ戻る」を押すと iframe の中で管理画面が開き、
        **画面がまるごと入れ子で二重に出た**（UX 一周 2026-09-27）。
        隠すのではなく、描かない。リンクは念のため `_top` で開く（枠の中で管理画面を開かない）。
    --}}
    @unless($canvas)
    <div class="preview-notice" role="status" data-testid="page-preview-notice">
        {{--
            公開中のページでも下書きは別に存在する。「まだ公開されていません」と言い切ると
            状態バッジ（公開中）と矛盾するため、**いま見ているのが下書きである**ことだけを述べる
        --}}
        <span>
            <strong>下書きのプレビューです。</strong>
            公開サイトには反映されていない変更が含まれることがあります（現在の状態:
            {{ $page->status->label() }}）。
        </span>
        <a href="{{ $backUrl }}" target="_top" data-testid="page-preview-back">編集画面へ戻る</a>
    </div>
    @endunless

    {{-- 公開ページと同じ帯（ヒーロー／題の帯）を Canvas にも描く。無いと、公開して初めて帯が出て驚く --}}
    @include('public.partials.doc-band', ['band' => $band ?? null])

    {!! $document !!}
@endsection
