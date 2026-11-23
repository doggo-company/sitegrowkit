{{--
    入力欄 1 つ。種類は FormFields::PUBLIC_TYPES に限られる（未認証の口に出せるものだけ）。

    **選択肢がまとまる項目は fieldset / legend で囲む。** 複数の input を 1 つの
    <label> で指すことはできず、for の指す先が無い label は支援技術から見て壊れている。
--}}
@php
    // 同じページに複数のフォームを置いても label が別の入力欄を指さない。
    $id = ($idPrefix ?? '').'f-'.$field->key;
    $value = old($field->key, $values[$field->key] ?? $field->default);
    $describedBy = [];
    if ($field->help !== '') { $describedBy[] = $id.'-help'; }
    if ($errors->has($field->key)) { $describedBy[] = $id.'-error'; }
    $described = $describedBy === [] ? null : implode(' ', $describedBy);
    $grouped = in_array($field->type, ['radio', 'checkbox'], true);
    $textType = ['email' => 'email', 'url' => 'url', 'tel' => 'tel', 'number' => 'number',
                 'date' => 'date', 'datetime' => 'datetime-local'][$field->type] ?? 'text';
@endphp

<div class="form-field">
    @if ($grouped)
        <fieldset class="form-field__group" @if($described) aria-describedby="{{ $described }}" @endif>
            <legend class="form-field__label">
                {{ $field->label }}
                @if($field->required)<span class="form-field__required">必須</span>@endif
            </legend>
            @php $selected = is_array($value) ? array_map(strval(...), $value) : []; @endphp
            @foreach ($field->choices as $i => $choice)
                <label class="form-field__check" for="{{ $id }}-{{ $i }}">
                    @if ($field->type === 'radio')
                        <input type="radio" id="{{ $id }}-{{ $i }}" name="{{ $field->key }}" value="{{ $choice }}"
                            @checked((string) $value === (string) $choice)
                            @if($field->required) required @endif>
                    @else
                        <input type="checkbox" id="{{ $id }}-{{ $i }}" name="{{ $field->key }}[]" value="{{ $choice }}"
                            @checked(in_array((string) $choice, $selected, true))>
                    @endif
                    <span>{{ $choice }}</span>
                </label>
            @endforeach
        </fieldset>

    @elseif ($field->type === 'boolean')
        <label class="form-field__check" for="{{ $id }}">
            <input type="checkbox" id="{{ $id }}" name="{{ $field->key }}" value="1"
                @checked(in_array($value, [true, 1, '1', 'on', 'true'], true))
                @if($field->required) required @endif
                @if($described) aria-describedby="{{ $described }}" @endif>
            <span>{{ $field->label }}@if($field->required)<span class="form-field__required">必須</span>@endif</span>
        </label>

    @else
        <label class="form-field__label" for="{{ $id }}">
            {{ $field->label }}
            @if($field->required)<span class="form-field__required">必須</span>@endif
        </label>

        @if ($field->type === 'textarea')
            <textarea id="{{ $id }}" name="{{ $field->key }}" rows="6"
                @if($field->required) required @endif
                @if($field->max !== null) maxlength="{{ $field->max }}" @endif
                @if($described) aria-describedby="{{ $described }}" @endif
                @if($errors->has($field->key)) aria-invalid="true" @endif>{{ $value }}</textarea>
        @elseif ($field->type === 'select')
            <select id="{{ $id }}" name="{{ $field->key }}"
                @if($field->required) required @endif
                @if($described) aria-describedby="{{ $described }}" @endif
                @if($errors->has($field->key)) aria-invalid="true" @endif>
                <option value="">選択してください</option>
                @foreach ($field->choices as $choice)
                    <option value="{{ $choice }}" @selected((string) $value === (string) $choice)>{{ $choice }}</option>
                @endforeach
            </select>
        @else
            <input type="{{ $textType }}" id="{{ $id }}" name="{{ $field->key }}" value="{{ $value }}"
                @if($field->required) required @endif
                @if($field->max !== null) maxlength="{{ $field->max }}" @endif
                @if($described) aria-describedby="{{ $described }}" @endif
                @if($errors->has($field->key)) aria-invalid="true" @endif>
        @endif
    @endif

    @if ($field->help !== '')
        <p class="form-field__help" id="{{ $id }}-help">{{ $field->help }}</p>
    @endif
    @error($field->key)
        <p class="form-field__error" id="{{ $id }}-error" role="alert">{{ $message }}</p>
    @enderror
</div>
