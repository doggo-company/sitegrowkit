{{-- 件数が増えても、古い記事へ進める共通のページ送り。URL生成はPaginatorに任せる。 --}}
@props(['items'])
@if ($items->hasPages())
    <nav aria-label="ページ送り">
        <ul class="pagination">
            <li>
                @if ($items->onFirstPage())
                    <span aria-disabled="true">前へ</span>
                @else
                    <a href="{{ $items->previousPageUrl() }}" rel="prev">前へ</a>
                @endif
            </li>
            <li><span aria-current="page">{{ $items->currentPage() }} / {{ $items->lastPage() }}</span></li>
            <li>
                @if ($items->hasMorePages())
                    <a href="{{ $items->nextPageUrl() }}" rel="next">次へ</a>
                @else
                    <span aria-disabled="true">次へ</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
