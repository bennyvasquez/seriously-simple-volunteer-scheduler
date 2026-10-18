{{-- Screen 35: the full weekly slot form. $slot is set when editing: the area stays, a box says what saving does to the shifts already on the calendar, and after a save the page says what it did and links the shifts that kept their old details. --}}
@extends('layouts.app')

@php
    $editing = $slot !== null;
    $saveUrl = $editing ? route('calendar.slots.update', $slot) : route('calendar.slots.store');
    $heading = $editing ? 'Edit the weekly slot' : 'Add a weekly slot';
@endphp

@section('title', $heading)
@section('main-class', 'slot-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week', ['date' => $values['valid_from']] + $areaQuery) }}">Calendar</a> / {{ $heading }}</nav>
<h1>{{ $heading }}</h1>
@if ($saved)
<div role="status" class="panel slot-saved">
<p>{{ $saved['summary'] }}@if ($saved['groups'] !== []) {{ $saved['groups'][0]['heading'] }}@endif</p>
@foreach ($saved['groups'] as $group)
@if (! $loop->first)<p>{{ $group['heading'] }}</p>@endif
<ul>
@foreach ($group['shifts'] as $kept)
<li><a href="{{ route('calendar.shift', [$kept['shift']] + $areaQuery) }}">{{ $kept['when'] }}</a></li>
@endforeach
</ul>
@endforeach
</div>
@endif

<div class="slot-layout">
<form method="POST" action="{{ $saveUrl }}" class="panel slot-fields">
@csrf
<input type="hidden" name="refresh" value="">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<fieldset class="slot-set">
@if ($editing)
<div class="field">
<span>Area</span>
<b>{{ $slot->area->name }}</b>
</div>
@else
<div class="field">
<label for="area">Area</label>
<select name="area" id="area" onchange="this.form.elements.refresh.value='1';this.form.submit()" @error('area') aria-invalid="true" @enderror>
@foreach ($manageable as $area)
<option value="{{ $area->id }}" @selected((int) $values['area'] === $area->id)>{{ $area->name }}</option>
@endforeach
</select>
@error('area')<span class="field-error">{{ $message }}</span>@enderror
</div>
@endif
<x-field name="title" label="What it's for (optional)" :value="$values['title']" maxlength="80" />
<div class="slot-grid">
<div class="field">
<label for="weekday">Day</label>
<select name="weekday" id="weekday" onchange="this.form.elements.refresh.value='1';this.form.submit()">
@foreach ($weekdays as $number => $name)
<option value="{{ $number }}" @selected((string) $values['weekday'] === (string) $number)>{{ $name }}</option>
@endforeach
</select>
@error('weekday')<span class="field-error">{{ $message }}</span>@enderror
</div>
<x-field name="start_time" onchange="this.form.elements.refresh.value='1';this.form.submit()" type="time" label="Starts at" :value="$values['start_time']" required />
<x-field name="end_time" onchange="this.form.elements.refresh.value='1';this.form.submit()" type="time" label="Ends at" :value="$values['end_time']" required />
</div>
<div class="slot-grid">
<x-field name="headcount" type="number" label="People needed" :value="$values['headcount']" min="1" max="200" required />
<x-field name="shadow_spots" type="number" label="Shadow spots" :value="$values['shadow_spots']" min="0" max="200" required />
</div>
<p class="muted">Shadow spots are extra places for people in training. They never count toward people needed and never send cover requests.</p>

<fieldset class="choices">
<legend>How often</legend>
<label class="check"><input type="radio" name="frequency" value="weekly" onchange="this.form.elements.refresh.value='1';this.form.submit()" @checked($values['frequency'] !== 'every_other_week')><span>Every week</span></label>
<label class="check"><input type="radio" name="frequency" value="every_other_week" onchange="this.form.elements.refresh.value='1';this.form.submit()" @checked($values['frequency'] === 'every_other_week')><span>Every other week<br><span class="muted">Starting the week of the first date below, which has to be on the day picked above.</span></span></label>
@error('frequency')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>

<fieldset class="choices">
<legend>Who fills it</legend>
<label class="check"><input type="radio" name="fills_by" value="regulars" @checked($values['fills_by'] !== 'rotation')><span>Regulars<br><span class="muted">The same person or people each time. Add them after saving.</span></span></label>
<label class="check"><input type="radio" name="fills_by" value="rotation" @checked($values['fills_by'] === 'rotation')><span>A rotation<br><span class="muted">No regular. Volunteers sign up one date at a time.</span></span></label>
@error('fills_by')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>

<div class="slot-grid">
<x-field name="valid_from" onchange="this.form.elements.refresh.value='1';this.form.submit()" type="date" label="First date" :value="$values['valid_from']" required />
<x-field name="valid_until" onchange="this.form.elements.refresh.value='1';this.form.submit()" type="date" label="Last date (optional)" :value="$values['valid_until']" />
</div>
</fieldset>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Save the slot</button>
<a href="{{ route('calendar.week', ['date' => $values['valid_from']] + $areaQuery) }}" class="btn">Cancel</a>
<noscript><button type="submit" class="btn" name="refresh" value="1">Update the preview</button></noscript>
</div>
</form>

<aside class="panel slot-preview" aria-labelledby="preview-heading">
<h2 id="preview-heading">What this makes</h2>
@if ($preview === null)
<p>Fill in the day, the times and the first date to see what this makes.</p>
@else
<p>{{ $preview['summary'] }}</p>
<p class="muted">{{ $preview['detail'] }}</p>
@endif
<p class="muted">Two slots can overlap if you want more than one shift at the same time.</p>
</aside>
</div>
@if ($existing)
<section class="panel slot-existing" aria-labelledby="existing-heading">
<h2 id="existing-heading">Changes apply to shifts nobody is on yet</h2>
<p class="muted">{{ implode(' ', $existing) }}</p>
<p class="muted">If you change the day, how often, or the first or last date, shifts nobody is on that no longer fit are cancelled. They stay on the calendar, marked cancelled.</p>
</section>
@endif
@endsection
