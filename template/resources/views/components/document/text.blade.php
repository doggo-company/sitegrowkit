{{-- PageDocument: text（プレーンテキスト。HTML は解釈しない）。
     改行はそのまま改行として出す（UX 一周 2026-09-28: 料金表を 1 行ずつ書いても 1 行につながっていた）。
     出し方は CSS の white-space: pre-line（document.css の .doc-text）。前後に空白を入れると
     それも改行として出るので、本文は開きタグと閉じタグの間に詰めて書く --}}
@php($text = (string) ($props['text'] ?? ''))
@if(trim($text) !== '')
<p id="{{ $nodeId }}" class="doc-text {{ $classes }}" @if($style) style="{{ $style }}" @endif>{{ $text }}</p>
@elseif(($context ?? null)?->preview)
{{-- まだ何も書いていないテキスト（UX 一周 3 周目 N3）。編集画面とプレビューだけに薄い字で出す --}}
<p id="{{ $nodeId }}" class="doc-text doc-placeholder {{ $classes }}" @if($style) style="{{ $style }}" @endif data-placeholder>テキストを入力</p>
@endif
