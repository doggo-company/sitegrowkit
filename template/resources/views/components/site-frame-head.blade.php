{{-- 店の枠に載せた kit の標準の画面（検索・フォーム）が読む見た目（UX 一周 2026-09-28）。
     枠の CSS は kit の class を知らないので、`forms.css`（class だけに当てる数十行）で補い、
     ボタンの色は枠の主な色（見本なら --green）に合わせる --}}
<link rel="stylesheet" href="{{ asset('forms.css') }}">
<style>:root { --color-primary: var(--green, {{ app(\App\Support\SiteFrame::class)->accent() }}); }</style>
