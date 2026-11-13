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
@endif
<div aria-label="People on this shift" role="group">
@forelse ($people as $person)
<div class="row"><span>{{ $person['name'] }}</span><span class="muted">{{ $person['role'] }}</span>@if ($person['remove'])<a href="{{ $person['remove'] }}" aria-label="Remove {{ $person['name'] }} from shift">Remove from shift</a>@endif</div>
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
<div class="row"><span>{{ $match->display_name }}<br>@if ($match->show_email)<span class="muted">{{ $match->email }}</span>@endif</span></div>
<div class="add-actions">
<button type="submit" class="btn btn-primary" name="type" value="regular" aria-label="Put {{ $match->display_name }} on this shift">Put on shift</button>
@if ($shift->shadow_spots > $shadowsTaken)<button type="submit" class="btn" name="type" value="shadow" aria-label="Put {{ $match->display_name }} on this shift as a shadow">Shadow</button>@endif
</div>
</form>
@empty
<p class="muted">Nobody matches "{{ $search['query'] }}". Only people with an active account who aren't already on this shift are listed.</p>
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
<label class="check"><input type="radio" name="edit" value="shift" @checked($staffedMessage === null) @disabled($staffedMessage !== null)><span>This shift<br><span class="muted">{{ $staffedMessage ?? 'Only '.$shift->local_date->format('l, F j').'. Later changes to the weekly slot won\'t overwrite it.' }}</span></span></label>
<label class="check"><input type="radio" name="edit" value="slot" @checked($staffedMessage !== null)><span>The weekly slot (shifts nobody is on yet)<br><span class="muted">{{ $shift->weeklySlot->describe() }}. Shifts with people on them keep their old details.</span></span></label>
</fieldset>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Continue</button>
<a href="{{ $panelUrl() }}" class="btn">Back</a>
</div>
</form>
@elseif ($editing === 'shift')
@if ($staffedMessage !== null)
<p>{{ $staffedMessage }}</p>
<div class="add-actions">
<a href="{{ $panelUrl() }}" class="btn">Back</a>
</div>
@else
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
<div class="add-actions">
<button type="submit" class="btn btn-primary">Save this shift</button>
<a href="{{ $panelUrl() }}" class="btn">Back</a>
</div>
</form>
@endif
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
