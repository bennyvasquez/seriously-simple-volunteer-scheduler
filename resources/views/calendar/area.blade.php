{{-- Add an area or edit one. A new area takes the next unused color; editing offers the colors by name, never by color alone. --}}
@extends('layouts.app')

@section('title', $area ? 'Edit '.$area->name : 'Add an area')
@section('main-class', 'slot-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}">Calendar</a> / {{ $area ? 'Edit '.$area->name : 'Add an area' }}</nav>
<h1>{{ $area ? 'Edit '.$area->name : 'Add an area' }}</h1>
<p class="muted">An area is a place or kind of work that needs people, such as Front desk. Every shift belongs to one area.</p>

<form method="POST" action="{{ $area ? route('calendar.areas.update', $area) : route('calendar.areas.store') }}" class="panel slot-fields">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<x-field name="name" label="Name" :value="old('name', $area?->name)" maxlength="100" required />
@if ($area)
<fieldset class="choices" @error('color') aria-describedby="color-error" @enderror>
<legend>Color on the calendar</legend>
@foreach ($colors as $color)
<label class="check"><input type="radio" name="color" value="{{ $color->value }}" @checked(old('color', $area->color->value) === $color->value)><span class="sw {{ $color->cssClass() }}"></span><span>{{ $color->label() }}</span></label>
@endforeach
@error('color')<span class="field-error" id="color-error">{{ $message }}</span>@enderror
</fieldset>
@else
<p class="muted">We'll pick a color for it. You can change it afterward.</p>
@endif
<div class="add-actions">
<button type="submit" class="btn btn-primary">{{ $area ? 'Save the area' : 'Add the area' }}</button>
<a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}" class="btn">Cancel</a>
</div>
</form>
@endsection
