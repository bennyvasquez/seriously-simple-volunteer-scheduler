{{-- Screen 37: add a closure. "What this cancels" is built on the server from the areas and days picked; ticking an area reloads it, and "Check what this cancels" reloads it after changing the days or with JavaScript off. With $closure set, the same page changes that closure: its area is fixed, "What this changes" lists the shifts it would cancel and bring back, and ?remove=1 shows the remove step. --}}
@extends('layouts.app')

@php($heading = $closure === null ? 'Add a closure' : 'Change the closure')
@section('title', $heading)
@section('main-class', 'slot-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}">Calendar</a> / {{ $heading }}</nav>
<div class="closure-head">
<h1>{{ $heading }}</h1>
<p class="muted">For days an area is closed, such as a holiday. Shifts on those days are cancelled, and nobody is asked to cover them.@if ($closure !== null) Days it no longer covers get their shifts back.@endif</p>
</div>

<div class="slot-layout">
<form method="POST" action="{{ $closure === null ? route('calendar.closures.store') : route('calendar.closures.update', $closure) }}" class="panel slot-fields">
@csrf
@error('closure')<p role="alert" class="field-error">{{ $message }}</p>@enderror
{{-- Ticking an area sets this and submits. The days don't, since typing a date would reload the page mid-entry; "Check what this cancels" does that. The save button sends refresh empty after it, so a form the browser restored on back or forward still saves. --}}
<input type="hidden" name="refresh" value="">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
@if ($closure !== null)
<p><b>Area</b><br>{{ $closure->area->name }}</p>
@else
<fieldset class="choices" @error('area') aria-describedby="area-error" @enderror>
<legend>Which areas</legend>
@foreach ($areas as $area)
<label class="check"><input type="checkbox" name="area[]" value="{{ $area->id }}" onchange="this.form.querySelector('input[name=refresh]').value='1';this.form.submit()" @checked(in_array($area->id, $values['area'], true))><span>{{ $area->name }}</span></label>
@endforeach
@error('area')<span class="field-error" id="area-error">{{ $message }}</span>@enderror
</fieldset>
@endif
<div class="slot-grid">
<x-field name="from" type="date" label="First day closed" :value="$values['from']" required />
<x-field name="to" type="date" label="Last day closed" :value="$values['to']" required />
</div>
<x-field name="reason" label="Reason" :value="$values['reason']" hint="Volunteers see the reason in the email and on the schedule." maxlength="200" required />
<div class="add-actions">
<button type="submit" class="btn btn-primary" name="refresh" value="">{{ $closure !== null ? 'Save the closure' : ($preview['button'] ?? 'Add the closure') }}</button>
<a href="{{ route('calendar.week', ['date' => $backDate] + $areaQuery) }}" class="btn">Cancel</a>
<button type="submit" class="btn" name="refresh" value="1">{{ $closure !== null ? 'Check what this changes' : 'Check what this cancels' }}</button>
</div>
</form>

@if ($closure !== null)
<aside class="panel slot-preview" aria-labelledby="changes-heading">
<h2 id="changes-heading">What this changes</h2>
@if ($changes === null)
<p>Pick the days to see what this changes.</p>
@elseif ($changes['cancels'] === [] && $changes['brings'] === [])
<p>No shifts are cancelled or brought back.</p>
@else
@if ($changes['cancels'] !== [])
<h3>Cancels</h3>
@foreach ($changes['cancels'] as $row)
<div class="row"><span>{{ $row['when'] }}</span><span class="muted">{{ $row['people'] }}</span></div>
@endforeach
@endif
@if ($changes['brings'] !== [])
<h3>Brings back</h3>
@foreach ($changes['brings'] as $row)
<div class="row"><span>{{ $row['when'] }}</span><span class="muted">{{ $row['people'] }}</span></div>
@endforeach
@endif
<p class="muted">{{ match (true) {
    $changes['people'] === 1 => 'We\'ll email the 1 person on these shifts.',
    $changes['people'] > 1 => 'We\'ll email the '.$changes['people'].' people on these shifts.',
    $changes['onShifts'] => '',
    default => 'Nobody is on these shifts, so nobody is emailed.',
} }}</p>
@endif
</aside>
@else
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
<p class="muted">{{ match (true) {
    $preview['people'] === 1 => 'We\'ll email the 1 person on these shifts that they\'re cancelled. ',
    $preview['people'] > 1 => 'We\'ll email the '.$preview['people'].' people on these shifts that they\'re cancelled. ',
    $preview['onShifts'] => '',
    default => 'Nobody is on these shifts, so nobody is emailed. ',
} }}Shifts for these days won't be made again later.</p>
@else
<p class="muted">Shifts won't be made for these days.</p>
@endif
@endif
</aside>
@endif
</div>
@if ($closure !== null)
<section class="panel" aria-labelledby="remove-heading">
@if ($removing !== null)
<h2 id="remove-heading">Remove this closure</h2>
<p>{{ $removing['text'] }}</p>
@if ($removing['started'])
<p class="muted">Days that have passed lose the Closed label. Their shifts stay cancelled.</p>
@endif
<form method="POST" action="{{ route('calendar.closures.remove', $closure) }}" class="add-actions">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<button type="submit" class="btn btn-primary">Remove the closure</button>
<a href="{{ route('calendar.closures.edit', ['closure' => $closure] + $areaQuery) }}" class="btn">Keep it</a>
</form>
@else
<a id="remove-heading" href="{{ route('calendar.closures.edit', ['closure' => $closure, 'remove' => 1] + $areaQuery) }}" class="btn">Remove this closure</a>
@endif
</section>
@endif
<p class="muted">Coordinators and admins add closures. Area leads see them on the schedule.</p>
@endsection
