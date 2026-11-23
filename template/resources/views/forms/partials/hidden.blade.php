{{-- 入力値の持ち回し。配列（複数選択）は name[] で 1 件ずつ出す --}}
@foreach ($values as $key => $value)
    @if (is_array($value))
        @foreach ($value as $one)
            <input type="hidden" name="{{ $key }}[]" value="{{ $one }}">
        @endforeach
    @elseif ($value !== null)
        <input type="hidden" name="{{ $key }}" value="{{ is_bool($value) ? ($value ? '1' : '0') : $value }}">
    @endif
@endforeach
