@props(['name', 'label', 'type' => 'text', 'hint' => null, 'value' => null, 'autocomplete' => null])
@php
    $error = $errors->first($name);
    $describedBy = trim(($hint ? $name.'-hint ' : '').($error ? $name.'-error' : ''));
@endphp
<label class="field">{{ $label }}
<input type="{{ $type }}" name="{{ $name }}" id="{{ $name }}"@if ($type !== 'password') value="{{ $value }}"@endif @if ($autocomplete) autocomplete="{{ $autocomplete }}"@endif @if ($describedBy) aria-describedby="{{ $describedBy }}"@endif @if ($error) aria-invalid="true"@endif {{ $attributes }}>
@if ($hint)<span class="field-hint" id="{{ $name }}-hint">{{ $hint }}</span>@endif
@if ($error)<span class="field-error" id="{{ $name }}-error">{{ $error }}</span>@endif
</label>
