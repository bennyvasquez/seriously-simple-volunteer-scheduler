{{-- Removing one person from this shift, or from their shifts across a range of dates, beside the week. Neither choice is picked to start with. "Check which shifts" submits the form, which comes back to this page with the list, so it works without JavaScript. --}}
@extends('calendar.week')

@php
    $first = $person->shortName();
    $backUrl = route('calendar.shift', [$shift] + $areaQuery);
    $weekday = $shift->local_date->format('l, F j');
    $count = $which === 'this' ? 1 : ($preview ? $preview['shifts']->count() : null);
    $label = 'Remove '.$first.' from '.($count === null ? 'shifts' : $count.' '.($count === 1 ? 'shift' : 'shifts'));
@endphp

@section('panel')
<section aria-label="Remove from shifts" class="add-panel">
<div class="add-head">
<h2>Remove {{ $personName }} from shifts</h2>
<a href="{{ $backUrl }}" class="btn btn-icon" aria-label="Close">x</a>
</div>
<div>
<p><b>{{ $shift->area->name }}</b></p>
<p>{{ $shift->whenText() }}</p>
</div>
<form method="POST" action="{{ route('calendar.shift.remove-person.store', $shift) }}">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<input type="hidden" name="assignment" value="{{ $assignment->id }}">
<fieldset class="choices">
<legend>Which shifts?</legend>
<label class="check"><input type="radio" name="which" value="this" @checked($which === 'this')><span>Just {{ $weekday }}</span></label>
<div class="check"><input type="radio" name="which" value="range" id="which-range" @checked($which === 'range')><label for="which-range">All of {{ $first }}'s shifts from <input type="date" name="from" value="{{ $values['from'] }}" aria-label="First date"> to <input type="date" name="to" value="{{ $values['to'] }}" aria-label="Last date"></label></div>
@error('which')<span class="field-error" role="alert">{{ $message }}</span>@enderror
</fieldset>
@if ($dateError)<p role="alert" class="field-error">{{ $dateError }}</p>@endif
<div class="add-actions"><button type="submit" class="btn" name="check" value="1">Check which shifts</button></div>

@if ($preview)
@if ($preview['shifts']->isEmpty())
<p>{{ $first }} isn't on any shifts you manage on those dates.</p>
@else
<p><b>{{ $first }} would be removed from these shifts:</b></p>
@foreach ($preview['shifts'] as $listed)
<p>{{ $listed->area->name }}, {{ $listed->whenText() }}@if ($listed->cancelled_at) (cancelled)@endif</p>
@endforeach
@endif
@if ($preview['lastOnCalendar'])<p class="muted">Shifts after {{ \Carbon\CarbonImmutable::parse($preview['lastOnCalendar'], 'UTC')->format('l, F j') }} aren't on the calendar yet, so they aren't included. To stop {{ $first }} working those, end their time as a regular.</p>@endif
@if ($preview['otherAreas'] === 1)<p class="muted">1 shift in areas you don't manage isn't included.</p>@elseif ($preview['otherAreas'] > 1)<p class="muted">{{ $preview['otherAreas'] }} shifts in areas you don't manage aren't included.</p>@endif
<input type="hidden" name="checked_from" value="{{ $values['from'] }}">
<input type="hidden" name="checked_to" value="{{ $values['to'] }}">
@endif

<div class="add-actions">
@if (! $preview || $preview['shifts']->isNotEmpty())
<button type="submit" class="btn btn-primary" @if ($preview) name="confirmed" value="1" @endif>{{ $label }}</button>
@endif
<a href="{{ $backUrl }}" class="btn">Keep them</a>
</div>
</form>
</section>
@endsection
