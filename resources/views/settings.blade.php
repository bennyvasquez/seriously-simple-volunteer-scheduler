{{-- Screen 38: organization settings. Name, time zone and weeks ahead are for admins; the Shadowing panel is for coordinators too. $isAdmin says which the viewer is. --}}
@extends('layouts.app')

@section('title', 'Organization settings')
@section('main-class', 'slot-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}">Calendar</a> / Settings</nav>
<h1>Organization settings</h1>
<p class="muted">{{ $isAdmin ? 'Only admins can change the organization and schedule settings.' : 'Coordinators can change the shadowing setting. Admins change the rest.' }}</p>
@if (session('status'))
<p role="status" class="cal-status">{{ session('status') }}</p>
@endif

@if ($isAdmin)
<form method="POST" action="{{ route('settings') }}" class="panel slot-fields">
@csrf
@method('PUT')
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<h2>The organization</h2>
<x-field name="name" label="Name" :value="old('name', $organization->name)" maxlength="150" required />
<div class="field">
<label for="timezone">Time zone</label>
<select name="timezone" id="timezone" aria-describedby="timezone-hint @error('timezone') timezone-error @enderror" @error('timezone') aria-invalid="true" @enderror>
@php $current = old('timezone', $organization->timezone); @endphp
@foreach ($timezones as $group => $zones)
<optgroup label="{{ $group }}">
@foreach ($zones as $timezone)
<option value="{{ $timezone }}" @selected($current === $timezone)>{{ $timezone }}</option>
@endforeach
</optgroup>
@endforeach
</select>
<span class="field-hint" id="timezone-hint">Shifts already made keep their exact time, so after a change they show at different clock times. New shifts use the new time zone.</span>
@error('timezone')<span class="field-error" id="timezone-error">{{ $message }}</span>@enderror
</div>

<h2>The schedule</h2>
<x-field name="weeks_ahead" type="number" label="Make shifts this many weeks ahead" :value="old('weeks_ahead', $organization->weeks_ahead)" hint="Every night, weekly slots turn into dated shifts this far ahead. More weeks lets volunteers plan further, and means more shifts to fix if a slot changes. You can choose from 1 to 26 weeks. Lowering it doesn't remove shifts already made." min="1" max="26" step="1" required />

<div class="add-actions">
<button type="submit" class="btn btn-primary">Save settings</button>
<a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}" class="btn">Cancel</a>
</div>
</form>
@endif

<form method="POST" action="{{ route('settings.shadowing') }}" class="panel slot-fields">
@csrf
@method('PUT')
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<h2>Shadowing</h2>
<x-field name="required_shadow_shifts" type="number" label="Shadow shifts before review" :value="old('required_shadow_shifts', $organization->required_shadow_shifts)" hint="Coordinators review someone once they've done this many. Changing it never un-clears anyone." min="1" max="10" step="1" required />
<div class="add-actions">
<button type="submit" class="btn btn-primary">Save shadowing</button>
@unless ($isAdmin)<a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}" class="btn">Cancel</a>@endunless
</div>
</form>
@endsection
