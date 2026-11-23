{{-- 独立ページと埋め込みの共通フォーム。Turnstile は複数配置でも一度だけ読み込む。 --}}
@if ($turnstileSiteKey !== '' && !($preview ?? false) && !($embedded ?? false))
    @once
        @push('head')
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endpush
    @endonce
@endif
    @if (isset($rateLimitMessage))
        <div class="form-page__errors" role="alert"><p>{{ $rateLimitMessage }}</p></div>
    @endif
    @if ($closed)
        {{-- 受付を終了したフォーム。URL は生きているが送信はできない（SPEC §C.1） --}}
        <p class="form-page__closed" role="status">このフォームは受付を終了しました。</p>
    @else
        @if ($errors->any())
            <div class="form-page__errors" role="alert">
                <p>入力内容をご確認ください。</p>
            </div>
        @endif

        <form method="post" action="{{ $action }}" class="form-page__form" novalidate>
            @csrf
            {{-- Canvas での誤送信を防ぐ。公開後は通常どおり入力できる。 --}}
            <fieldset @disabled($preview ?? false) class="form-page__fields">
            @if ($preview ?? false)<p>プレビューでは送信できません。</p>@endif

            @foreach ($fields as $field)
                @include('forms.partials.field', ['field' => $field, 'values' => $values])
            @endforeach

            {{--
                人には見えない項目（SPEC §F.2）。
                display:none は一部の bot が読むので、位置と大きさで隠したうえで
                tabindex="-1" と aria-hidden="true" でキーボードと支援技術から外す。
            --}}
            <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
                <label for="{{ ($idPrefix ?? '').$honeypot }}">この欄は入力しないでください</label>
                <input type="text" id="{{ ($idPrefix ?? '').$honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
            </div>

            @if ($turnstileSiteKey !== '' && !($preview ?? false))
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-language="ja"></div>
                @error('cf-turnstile-response')<p class="form-field__error" role="alert">{{ $message }}</p>@enderror
            @endif

            <button type="submit" class="form-page__submit">{{ $submitLabel }}</button>
            </fieldset>
        </form>
    @endif
