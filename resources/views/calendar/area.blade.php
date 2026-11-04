{{-- Add an area or edit one. A new area takes the next unused color; editing offers the colors by name, never by color alone, and lists the area's weekly slots that haven't ended. --}}
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
<x-field name="lead_name" label="What do you call this area's leads?" hint="For example Shift lead. Leave it blank for Area lead." :value="old('lead_name', $area->lead_name)" maxlength="40" />
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

@if ($area)
<section class="panel slot-fields" aria-labelledby="slots-heading">
<h2 id="slots-heading">Weekly slots</h2>
@forelse ($slots as $slot)
<div class="row">
@php
    $notes = array_filter([
        $slot->is_rotation ? 'A rotation.' : null,
        $slot->valid_from->toDateString() > $today ? 'Starts '.$slot->valid_from->format('l, F j').'.' : null,
        $slot->valid_until ? 'Last date '.$slot->valid_until->format('l, F j').'.' : null,
    ]);
@endphp
<span>{{ $slot->describe() }}@if ($slot->title) <span class="muted">({{ $slot->title }})</span>@endif @if ($notes !== [])<br><span class="muted">{{ implode(' ', $notes) }}</span>@endif</span>
@can('manageSchedule', $area)
<span><a href="{{ route('calendar.slots.edit', ['slot' => $slot] + $areaQuery) }}">Edit</a>@if (! $slot->is_rotation) · <a href="{{ route('calendar.slots.regulars.create', ['slot' => $slot] + $areaQuery) }}">Add a regular</a>@endif</span>
@endcan
</div>
@empty
<p class="muted">{{ $area->name }} has no weekly slots yet. Add one from the calendar.</p>
@endforelse
</section>
@endif
@endsection
