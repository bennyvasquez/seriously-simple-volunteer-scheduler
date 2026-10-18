{{-- Screen 31: the week with the add panel beside it. Choosing a repeat reloads the panel (with JavaScript off, through the button) so it can ask who fills the slot and when it ends. --}}
@extends('calendar.week')

@php
    $repeating = $values['repeats'] !== 'none';
@endphp

@section('panel')
<section aria-label="Add to the calendar" class="add-panel">
<div class="add-head">
<h2>Add to the calendar</h2>
<a href="{{ route('calendar.week', ['date' => $values['date']] + $areaQuery) }}" class="btn btn-icon" aria-label="Close">x</a>
</div>
<form method="POST" action="{{ route('calendar.add.store') }}">
@csrf
<input type="hidden" name="refresh" value="">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<div class="field">
<label for="area">Area</label>
<select name="area" id="area" @error('area') aria-invalid="true" @enderror>
@foreach ($manageable as $area)
<option value="{{ $area->id }}" @selected($values['area'] === $area->id)>{{ $area->name }}</option>
@endforeach
</select>
@error('area')<span class="field-error">{{ $message }}</span>@enderror
</div>
<x-field name="title" label="What it's for (optional)" :value="$values['title']" maxlength="80" />
<div class="add-times">
<x-field name="date" type="date" label="Date" :value="$values['date']" required />
<x-field name="start_time" type="time" label="From" :value="$values['start_time']" required />
<x-field name="end_time" type="time" label="To" :value="$values['end_time']" required />
</div>
<div class="field">
<label for="repeats">Repeats</label>
<select name="repeats" id="repeats" onchange="this.form.elements.refresh.value='1';this.form.submit()">
@foreach ($repeatOptions as $key => $label)
<option value="{{ $key }}" @selected($values['repeats'] === $key)>{{ $label }}</option>
@endforeach
</select>
@error('repeats')<span class="field-error">{{ $message }}</span>@enderror
</div>
@if ($repeating)
<fieldset class="choices">
<legend>Ends</legend>
<label class="check"><input type="radio" name="ends" value="never" @checked($values['ends'] !== 'on')><span>No end date yet</span></label>
<label class="check"><input type="radio" name="ends" value="on" @checked($values['ends'] === 'on')><span>On a date</span></label>
<x-field name="ends_on" type="date" label="Last date" :value="$values['ends_on']" />
@error('ends')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>
<fieldset class="choices">
<legend>Who fills it</legend>
<label class="check"><input type="radio" name="fills_by" value="regulars" @checked($values['fills_by'] !== 'rotation')><span>Regulars<br><span class="muted">The same people each time. Add them after saving.</span></span></label>
<label class="check"><input type="radio" name="fills_by" value="rotation" @checked($values['fills_by'] === 'rotation')><span>A rotation<br><span class="muted">No regular. Volunteers sign up one date at a time.</span></span></label>
@error('fills_by')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>
@endif
<div class="add-times add-two">
<x-field name="headcount" type="number" label="People needed" :value="$values['headcount']" min="1" max="200" required />
<x-field name="shadow_spots" type="number" label="Shadow spots" :value="$values['shadow_spots']" min="0" max="200" required />
</div>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Save</button>
<button type="submit" class="btn" name="more" value="1">More options</button>
<noscript><button type="submit" class="btn" name="refresh" value="1">Show repeat options</button></noscript>
</div>
</form>
</section>
@endsection
