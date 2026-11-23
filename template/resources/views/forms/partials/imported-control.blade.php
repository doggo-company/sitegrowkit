{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。**直して構いません。**

    入力欄の**制御要素だけ**（R-12 / 受付の一本化）。

    外枠（`<div class="field">` と `<label>`）は `forms/imported.blade.php` が
    原本の意匠で出すので、ここでは出さない。kit 既定（`forms/partials/field.blade.php`）は
    外枠ごと出すため、原本の外枠が `<p>` のときに入れられない（段落が割れる）。

    取り込んだ案件では `agentcms template apply` が原本の制御要素から写した 1 セットで
    ここを作り直す。同梱見本の分は、見本サイトの入力欄と同じ素の要素にしてある。 --}}
@php
    $value = old($field->key, $values[$field->key] ?? $field->default);
    $textType = ['email' => 'email', 'url' => 'url', 'tel' => 'tel', 'number' => 'number',
                 'date' => 'date', 'datetime' => 'datetime-local'][$field->type] ?? 'text';
@endphp
@if (in_array($field->type, ['radio', 'checkbox'], true))
    @php $selected = is_array($value) ? array_map(strval(...), $value) : []; @endphp
    @foreach ($field->choices as $i => $choice)
        <label for="{{ $fieldId }}-{{ $i }}">
            @if ($field->type === 'radio')
                <input type="radio" id="{{ $fieldId }}-{{ $i }}" name="{{ $field->key }}" value="{{ $choice }}"
                    @checked((string) $value === (string) $choice)
                    @if($field->required) required @endif>
            @else
                <input type="checkbox" id="{{ $fieldId }}-{{ $i }}" name="{{ $field->key }}[]" value="{{ $choice }}"
                    @checked(in_array((string) $choice, $selected, true))>
            @endif
            <span>{{ $choice }}</span>
        </label>
    @endforeach
@elseif ($field->type === 'boolean')
    <input type="checkbox" id="{{ $fieldId }}" name="{{ $field->key }}" value="1"
        @checked(in_array($value, [true, 1, '1', 'on', 'true'], true))
        @if($field->required) required @endif>
@elseif ($field->type === 'textarea')
    <textarea id="{{ $fieldId }}" name="{{ $field->key }}" rows="6"
        @if($field->required) required @endif
        @if($field->max !== null) maxlength="{{ $field->max }}" @endif
        @if($errors->has($field->key)) aria-invalid="true" @endif>{{ $value }}</textarea>
@elseif ($field->type === 'select')
    <select id="{{ $fieldId }}" name="{{ $field->key }}"
        @if($field->required) required @endif
        @if($errors->has($field->key)) aria-invalid="true" @endif>
        <option value="">選択してください</option>
        @foreach ($field->choices as $choice)
            <option value="{{ $choice }}" @selected((string) $value === (string) $choice)>{{ $choice }}</option>
        @endforeach
    </select>
@else
    <input type="{{ $textType }}" id="{{ $fieldId }}" name="{{ $field->key }}" value="{{ $value }}"
        @if($field->required) required @endif
        @if($field->max !== null) maxlength="{{ $field->max }}" @endif
        @if($errors->has($field->key)) aria-invalid="true" @endif>
@endif
