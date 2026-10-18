{{-- Screen 37: add a closure. "What this cancels" is built on the server from the areas and days picked; changing them reloads it, and "Check what this cancels" does the same with JavaScript off. --}}
@extends('layouts.app')

@section('title', 'Add a closure')
@section('main-class', 'slot-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}">Calendar</a> / Add a closure</nav>
<div class="closure-head">
<h1>Add a closure</h1>
<p class="muted">For days an area is closed, such as a holiday. Shifts on those days are cancelled, and nobody is asked to cover them.</p>
</div>

<div class="slot-layout">
<form method="POST" action="{{ route('calendar.closures.store') }}" class="panel slot-fields">
@csrf
@error('closure')<p role="alert" class="field-error">{{ $message }}</p>@enderror
{{-- Changing a field sets this and submits. The save button sends refresh empty after it, so a form the browser restored on back or forward still saves. --}}
<input type="hidden" name="refresh" value="">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<fieldset class="choices" @error('area') aria-describedby="area-error" @enderror>
<legend>Which areas</legend>
@foreach ($areas as $area)
<label class="check"><input type="checkbox" name="area[]" value="{{ $area->id }}" onchange="this.form.querySelector('input[name=refresh]').value='1';this.form.submit()" @checked(in_array($area->id, $values['area'], true))><span>{{ $area->name }}</span></label>
@endforeach
@error('area')<span class="field-error" id="area-error">{{ $message }}</span>@enderror
</fieldset>
<div class="slot-grid">
<x-field name="from" type="date" label="First day closed" :value="$values['from']" onchange="this.form.querySelector('input[name=refresh]').value='1';this.form.submit()" required />
<x-field name="to" type="date" label="Last day closed" :value="$values['to']" onchange="this.form.querySelector('input[name=refresh]').value='1';this.form.submit()" required />
</div>
<x-field name="reason" label="Reason" :value="$values['reason']" hint="Volunteers see the reason in the email and on the schedule." maxlength="200" required />
<div class="add-actions">
<button type="submit" class="btn btn-primary" name="refresh" value="">{{ $preview['button'] ?? 'Add the closure' }}</button>
<a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}" class="btn">Cancel</a>
<noscript><button type="submit" class="btn" name="refresh" value="1">Check what this cancels</button></noscript>
</div>
</form>

<aside class="panel slot-preview" aria-labelledby="cancels-heading">
<h2 id="cancels-heading">What this cancels</h2>
@if ($preview === null)
<p>Pick the areas and days to see what this cancels.</p>
@else
@forelse ($preview['rows'] as $row)
<div class="row"><span>{{ $row['when'] }}</span><span class="muted">{{ $row['people'] }}</span></div>
@empty
<p>No shifts are on the calendar for these days.</p>
@endforelse
@if ($preview['already'] > 0)
<p class="muted">{{ $preview['already'] === 1 ? '1 shift on these days is already cancelled and stays as it is.' : $preview['already'].' shifts on these days are already cancelled and stay as they are.' }}</p>
@endif
@if ($preview['count'] > 0)
<p class="muted">{{ match ($preview['people']) {
    0 => 'Nobody is on these shifts, so nobody is emailed.',
    1 => 'We\'ll email the 1 person on these shifts that they\'re cancelled.',
    default => 'We\'ll email the '.$preview['people'].' people on these shifts that they\'re cancelled.',
} }} Shifts for these days won't be made again later.</p>
@else
<p class="muted">Shifts won't be made for these days.</p>
@endif
@endif
</aside>
</div>
<p class="muted">Coordinators and admins add closures. Area leads see them on the schedule.</p>
@endsection
