{{-- Screen 32: one shift's details beside the week. A shift that has started or been cancelled shows read-only. Searching, editing and cancelling work without JavaScript through ?q=, ?edit= and ?cancel=1. Edit on a repeating shift asks first whether to change this shift or the weekly slot; "This and following shifts" comes with the editing plan. --}}
@extends('calendar.week')

@php
    $backUrl = route('calendar.week', ['date' => $shift->local_date->toDateString()] + $areaQuery);
    $panelUrl = fn (array $extra = []) => route('calendar.shift', [$shift] + $extra + $areaQuery);
    $places = $shift->people_needed;
    $filled = $shift->filledCount();
@endphp

@section('panel')
<section aria-label="Shift details" class="add-panel">
<div class="add-head">
<h2>{{ $shift->area->name }}</h2>
<a href="{{ $backUrl }}" class="btn btn-icon" aria-label="Close">x</a>
</div>
@error('shift')<p role="alert" class="field-error">{{ $message }}</p>@enderror
<div>
<p><b>{{ $shift->local_date->format('l, F j') }}</b></p>
<p>{{ \App\Calendar\TimeFormat::range($shift->starts_at->setTimezone($organization->timezone), $shift->ends_at->setTimezone($organization->timezone)) }}</p>
@if ($shift->title)<p>{{ $shift->title }}</p>@endif
<p class="muted">{{ $repeats }}</p>
</div>
@if ($shift->cancelled_at)
<p><b>Cancelled: {{ $shift->cancel_reason }}</b></p>
@if ($cancelledBy === 'closure')
<p class="muted">Cancelled by the {{ $closure?->reason ?? $shift->cancel_reason }} closure. {!! $canChangeClosure ? '<a href="'.e(route('calendar.closures.edit', [$closure] + $areaQuery)).'">Change the closure</a>' : 'Change the closure' !!} to bring it back.</p>
@elseif ($cancelledBy === 'slot')
<p class="muted">The weekly slot doesn't run on this date any more. Change the slot to bring it back.</p>
@elseif ($restoreBlocked)
<p class="muted">{{ $restoreBlocked }}</p>
@endif
@endif
<div aria-label="People on this shift" role="group">
@forelse ($people as $person)
<div class="row"><span>{{ $person['name'] }}</span><span class="muted">{{ $person['role'] }}</span>@if ($person['remove'])<a href="{{ $person['remove'] }}" aria-label="Remove {{ $person['name'] }} from shift">Remove from shift</a>@endif @if ($person['end'])<a href="{{ $person['end'] }}" aria-label="End {{ $person['name'] }}'s time as a regular">End regular</a>@endif
@if (($person['noShow'] ?? false) || ($person['canMark'] ?? false))
@if ($person['noShow'])<b>No-show</b>@endif
@if ($person['canMark'])
<form method="POST" action="{{ route('calendar.shift.no-show', $shift) }}" class="inline-form">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<input type="hidden" name="assignment" value="{{ $person['assignment'] }}">
<input type="hidden" name="no_show" value="{{ $person['noShow'] ? 0 : 1 }}">
<button type="submit" class="btn btn-small" aria-label="{{ $person['noShow'] ? 'Undo no-show for' : 'Mark no-show for' }} {{ $person['name'] }}">{{ $person['noShow'] ? 'Undo no-show' : 'Mark no-show' }}</button>
</form>
@endif
@endif
</div>
@empty
<p class="muted">Nobody is on this shift yet.</p>
@endforelse
</div>
<p class="muted">@if ($filled > $places){{ $filled }} people on it. It needs {{ $places }}.@else{{ $filled }} of {{ $places }} {{ $places === 1 ? 'place' : 'places' }} filled.@endif @if ($shift->shadow_spots > 0) {{ $shadowsTaken }} of {{ $shift->shadow_spots }} shadow {{ $shift->shadow_spots === 1 ? 'spot' : 'spots' }} taken.@endif</p>

@if ($over)
<form method="POST" action="{{ route('calendar.shift.assign', $shift) }}">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<input type="hidden" name="person" value="{{ $over->id }}">
<input type="hidden" name="type" value="regular">
<input type="hidden" name="over_confirmed" value="1">
<p role="alert"><b>This puts {{ $shift->local_date->format('l, F j') }} at {{ $filled + 1 }} people. It needs {{ $places }}.</b></p>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Put {{ $over->firstName() }} on anyway</button>
<a href="{{ $panelUrl() }}" class="btn">Keep it as it is</a>
</div>
</form>
@elseif ($picking)
<form method="GET" action="{{ route('calendar.shift', $shift) }}" role="search">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<div class="field">
<label for="q">Find a person by name{{ $byEmail ? ' or email' : '' }}</label>
<input type="search" name="q" id="q" value="{{ $search['query'] }}" minlength="2" autocomplete="off" @if ($search['tooShort']) aria-invalid="true" aria-describedby="q-error" @endif>
@if ($search['tooShort'])<span class="field-error" id="q-error">Type at least 2 letters.</span>@endif
</div>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Search</button>
<a href="{{ $panelUrl() }}" class="btn">Close search</a>
</div>
</form>
@if ($search['query'] !== '' && ! $search['tooShort'])
@forelse ($search['matches'] as $match)
<form method="POST" action="{{ route('calendar.shift.assign', $shift) }}">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<input type="hidden" name="person" value="{{ $match->id }}">
<div class="row"><span>{{ $match->display_name }}@if ($match->shadowing) <span class="tag">Shadowing</span>@endif @unless ($match->isCleared()) <span class="tag">Not cleared yet</span>@endunless<br>@if ($match->show_email)<span class="muted">{{ $match->email }}</span>@endif</span></div>
<div class="add-actions">
<button type="submit" class="btn btn-primary" name="type" value="regular" aria-label="Put {{ $match->display_name }} on this shift">Put on shift</button>
@if (! $match->shadowing && $shift->shadow_spots > $shadowsTaken)<button type="submit" class="btn" name="type" value="shadow" aria-label="Put {{ $match->display_name }} on this shift as a shadow">Shadow</button>@endif
</div>
</form>
@empty
<p class="muted">Nobody matches "{{ $search['query'] }}". Only people with an active account who don't already have a place on this shift are listed.</p>
@endforelse
@endif
@endif

@if ($editing === 'choose')
<form method="GET" action="{{ route('calendar.shift', $shift) }}">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<fieldset class="choices">
<legend>Edit which shifts?</legend>
<label class="check"><input type="radio" name="edit" value="shift" checked><span>This shift<br><span class="muted">Only {{ $shift->local_date->format('l, F j') }}. Later changes to the weekly slot won't overwrite it.@if ($emailCount > 0) {{ $emailCount === 1 ? 'The 1 person on it is' : 'The '.$emailCount.' people on it are' }} emailed if the time or what it's for changes.@endif</span></span></label>
<label class="check"><input type="radio" name="edit" value="slot"><span>The weekly slot (future shifts nobody changed on their own)<br><span class="muted">{{ $shift->weeklySlot->describe() }}. Shifts with people on them change too, unless that would double-book someone or leave too few shadow spots. The people on them are emailed if the time or what it's for changes.</span></span></label>
</fieldset>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Continue</button>
<a href="{{ $panelUrl() }}" class="btn">Back</a>
</div>
</form>
@elseif ($editing === 'shift')
<form method="POST" action="{{ route('calendar.shift.update', $shift) }}">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<x-field name="title" label="What it's for (optional)" :value="$editValues['title']" maxlength="80" />
<div class="slot-grid">
<x-field name="start_time" type="time" label="Starts at" :value="$editValues['start_time']" required />
<x-field name="end_time" type="time" label="Ends at" :value="$editValues['end_time']" required />
</div>
<div class="slot-grid">
<x-field name="people_needed" type="number" label="People needed" :value="$editValues['people_needed']" min="1" max="200" required />
<x-field name="shadow_spots" type="number" label="Shadow spots" :value="$editValues['shadow_spots']" min="0" max="200" required />
</div>
@if ($shift->weeklySlot)<p class="muted">Only {{ $shift->local_date->format('l, F j') }}. Later changes to the weekly slot won't overwrite it.</p>@endif
@if ($emailCount > 0)<p class="muted">{{ $emailCount === 1 ? 'The 1 person on it is' : 'The '.$emailCount.' people on it are' }} emailed if the time or what it's for changes.</p>@endif
<div class="add-actions">
<button type="submit" class="btn btn-primary">Save this shift</button>
<a href="{{ $panelUrl() }}" class="btn">Back</a>
</div>
</form>
@elseif ($cancelling)
<form method="POST" action="{{ route('calendar.shift.cancel', $shift) }}">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<x-field name="reason" label="Why is it cancelled?" :value="old('reason')" maxlength="200" required hint="{{ $emailCount === 0 ? 'Nobody will be emailed.' : ($emailCount === 1 ? '1 person' : $emailCount.' people').' will be emailed this reason.' }} The shift stays on the calendar, marked cancelled." />
<div class="add-actions">
<button type="submit" class="btn btn-primary">Cancel this date</button>
<a href="{{ $panelUrl() }}" class="btn">Keep it</a>
</div>
</form>
@elseif ($restoring)
<form method="POST" action="{{ route('calendar.shift.restore', $shift) }}">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<p>{{ $restoreCounts['emailed'] === 0 ? 'Nobody is emailed.' : ($restoreCounts['emailed'] === 1 ? '1 person' : $restoreCounts['emailed'].' people').' will be emailed that it\'s back on.' }}</p>
@if ($restoreCounts['removedCount'] > 0)
<p>{{ $restoreCounts['removed'] }} {{ $restoreCounts['removedCount'] === 1 ? 'is' : 'are' }} on another shift at that time and will be removed from this one.</p>
@endif
<div class="add-actions">
<button type="submit" class="btn btn-primary">Bring it back</button>
<a href="{{ $panelUrl() }}" class="btn">Keep it cancelled</a>
</div>
</form>
@elseif ($canRestore)
<div class="add-actions">
<a href="{{ $panelUrl(['restore' => 1]) }}" class="btn btn-primary">Bring this shift back</a>
</div>
@elseif ($canEdit || $canPut || $canCancel || $canAddRegular)
<div class="add-actions">
@if ($canEdit)<a href="{{ $panelUrl(['edit' => 1]) }}" class="btn{{ $canPut ? '' : ' btn-primary' }}">Edit</a>@endif
@if ($canPut && ! $picking)<a href="{{ $panelUrl(['pick' => 1]) }}" class="btn btn-primary">Put someone on it</a>@endif
@if ($canAddRegular)<a href="{{ route('calendar.slots.regulars.create', [$shift->weeklySlot, 'date' => $shift->local_date->toDateString()] + $areaQuery) }}" class="btn">Add a regular</a>@endif
@if ($canCancel)<a href="{{ $panelUrl(['cancel' => 1]) }}" class="btn">Cancel this date</a>@endif
</div>
@endif
</section>
@endsection
