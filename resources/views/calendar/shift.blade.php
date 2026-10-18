{{-- Screen 32: one shift's details beside the week. A shift that has started or been cancelled shows read-only. Searching and cancelling work without JavaScript through ?q= and ?cancel=1. --}}
@extends('calendar.week')

@php
    $backUrl = route('calendar.week', ['date' => $shift->local_date->toDateString()] + $areaQuery);
    $panelUrl = fn (array $extra = []) => route('calendar.shift', [$shift] + $extra + $areaQuery);
    $places = $shift->headcount;
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
@endif
<div aria-label="People on this shift" role="group">
@forelse ($people as $person)
<div class="row"><span>{{ $person['name'] }}</span><span class="muted">{{ $person['role'] }}</span></div>
@empty
<p class="muted">Nobody is on this shift yet.</p>
@endforelse
</div>
<p class="muted">{{ $filled }} of {{ $places }} {{ $places === 1 ? 'place' : 'places' }} filled.@if ($shift->shadow_spots > 0) {{ $shadowsTaken }} of {{ $shift->shadow_spots }} shadow {{ $shift->shadow_spots === 1 ? 'spot' : 'spots' }} taken.@endif</p>

@if ($picking)
<form method="GET" action="{{ route('calendar.shift', $shift) }}" role="search">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<div class="field">
<label for="q">Find a person by name or email</label>
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
<div class="row"><span>{{ $match->name }}<br><span class="muted">{{ $match->email }}</span></span></div>
<div class="add-actions">
@if ($shift->openPlaces() > 0)<button type="submit" class="btn btn-primary" name="type" value="regular" aria-label="Put {{ $match->name }} on this shift">Put on shift</button>@endif
@if ($shift->shadow_spots > $shadowsTaken)<button type="submit" class="btn" name="type" value="shadow" aria-label="Put {{ $match->name }} on this shift as a shadow">Shadow</button>@endif
</div>
</form>
@empty
<p class="muted">Nobody matches "{{ $search['query'] }}". Only people with an active account who aren't already on this shift are listed.</p>
@endforelse
@if ($shift->openPlaces() === 0 && $shift->shadow_spots <= $shadowsTaken)<p class="muted">This shift is full. Raise the number of people needed first.</p>@endif
@endif
@endif

@if ($cancelling)
<form method="POST" action="{{ route('calendar.shift.cancel', $shift) }}">
@csrf
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<x-field name="reason" label="Why is it cancelled?" :value="old('reason')" maxlength="200" required hint="{{ $people->isEmpty() ? 'Nobody is on this shift.' : 'The '.($people->count() === 1 ? 'person' : $people->count().' people').' on it will be emailed this reason. The shift stays on the calendar, marked cancelled.' }}" />
<div class="add-actions">
<button type="submit" class="btn btn-primary">Cancel this date</button>
<a href="{{ $panelUrl() }}" class="btn">Keep it</a>
</div>
</form>
@elseif ($canAssign || $canCancel)
<div class="add-actions">
@if ($canAssign && ! $picking)<a href="{{ $panelUrl(['pick' => 1]) }}" class="btn btn-primary">Put someone on it</a>@endif
@if ($canCancel)<a href="{{ $panelUrl(['cancel' => 1]) }}" class="btn">Cancel this date</a>@endif
</div>
@endif
</section>
@endsection
