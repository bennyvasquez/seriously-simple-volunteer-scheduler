{{-- Screen 36: adding a regular to a weekly slot, beside the week. Choosing the person works without JavaScript through ?q= and ?person=; every other week shows the weeks of the slot's current alternating regulars as choices. --}}
@extends('calendar.week')

@php
    $dateParam = $date->toDateString();
    $typed = array_filter(array_intersect_key($values, array_flip(['frequency', 'weeks', 'starts_on', 'ends_on'])), fn ($value) => $value !== '');
    $keep = fn (array $extra = []) => $extra + ['slot' => $slot, 'date' => $dateParam] + $typed + $areaQuery;
    $backUrl = route('calendar.week', ['date' => $dateParam] + $areaQuery);
    $everyOther = $values['frequency'] === 'every_other_week';
    $picked = $values['weeks'] !== '' ? $values['weeks'] : ($weekOptions[0]['value'] ?? 'pick');
@endphp

@section('panel')
<section aria-label="Add a regular" class="add-panel">
<div class="add-head">
<h2>Add a regular</h2>
<a href="{{ $backUrl }}" class="btn btn-icon" aria-label="Close">x</a>
</div>
<div>
<p><b>{{ $slotText }}</b></p>
<p>{{ $slot->area->name }}. {{ $needs }}</p>
</div>
@error('slot')<p role="alert" class="field-error">{{ $message }}</p>@enderror

<div class="field">
<span><b>Who</b></span>
@if ($person)
<div class="row"><span>{{ $person->display_name }}</span><a href="{{ route('calendar.slots.regulars.create', $keep()) }}">Change</a></div>
@else
<form method="GET" action="{{ route('calendar.slots.regulars.create', $slot) }}" role="search">
<input type="hidden" name="date" value="{{ $dateParam }}">
@foreach ($typed as $name => $value)
<input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<div class="field">
<label for="q">Find a person by name{{ $byEmail ? ' or email' : '' }}</label>
<input type="search" name="q" id="q" value="{{ $search['query'] }}" minlength="2" autocomplete="off" @if ($search['tooShort']) aria-invalid="true" aria-describedby="q-error" @endif>
@if ($search['tooShort'])<span class="field-error" id="q-error">Type at least 2 letters.</span>@endif
</div>
<div class="add-actions"><button type="submit" class="btn">Search</button></div>
</form>
@if ($search['query'] !== '' && ! $search['tooShort'])
@forelse ($search['matches'] as $match)
<div class="row"><span>{{ $match->display_name }}@unless ($match->isCleared()) <span class="tag">Not cleared yet</span>@endunless<br>@if ($match->show_email)<span class="muted">{{ $match->email }}</span>@endif</span><a href="{{ route('calendar.slots.regulars.create', $keep(['person' => $match->id])) }}" aria-label="Choose {{ $match->display_name }}">Choose</a></div>
@empty
<p class="muted">Nobody matches "{{ $search['query'] }}". Only people with an active account who aren't already regulars here are listed.</p>
@endforelse
@endif
@endif
@error('user')<span class="field-error">{{ $message }}</span>@enderror
</div>

@if ($overDates !== [] && $person)
<form method="POST" action="{{ route('calendar.slots.regulars.store', $slot) }}">
@csrf
<input type="hidden" name="date" value="{{ $dateParam }}">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<input type="hidden" name="user" value="{{ $person->id }}">
@foreach ($typed as $name => $value)
<input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
<input type="hidden" name="over_confirmed" value="1">
<p role="alert"><b>Adding {{ $person->shortName() }} puts {{ count($overDates) === 1 ? 'this date' : 'these dates' }} over the people needed.</b></p>
@foreach (array_slice($overDates, 0, 5) as $over)
<p>{{ \Carbon\CarbonImmutable::parse($over['date'], 'UTC')->format('l, F j') }} at {{ $over['people'] }} people. It needs {{ $over['needed'] }}.</p>
@endforeach
@if (count($overDates) > 5)<p class="muted">and {{ count($overDates) - 5 }} more {{ count($overDates) - 5 === 1 ? 'date' : 'dates' }}</p>@endif
<div class="add-actions">
<button type="submit" class="btn btn-primary">Add {{ $person->shortName() }} anyway</button>
<a href="{{ route('calendar.slots.regulars.create', $keep(['person' => $person->id])) }}" class="btn">Change the dates</a>
</div>
</form>
@else
<form method="POST" action="{{ route('calendar.slots.regulars.store', $slot) }}">
@csrf
<input type="hidden" name="date" value="{{ $dateParam }}">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
@if ($person)<input type="hidden" name="user" value="{{ $person->id }}">@endif

@if ($canChooseFrequency)
<fieldset class="choices">
<legend>How often</legend>
<label class="check"><input type="radio" name="frequency" value="weekly" @checked(! $everyOther)><span>Every week</span></label>
<label class="check"><input type="radio" name="frequency" value="every_other_week" @checked($everyOther)><span>Every other week</span></label>
@error('frequency')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>
<fieldset class="choices">
<legend>Which weeks (every other week only)</legend>
@foreach ($weekOptions as $option)
<label class="check"><input type="radio" name="weeks" value="{{ $option['value'] }}" @checked($picked === $option['value'])><span>{{ $option['label'] }}<br><span class="muted">{{ $option['detail'] }}</span></span></label>
@endforeach
<label class="check"><input type="radio" name="weeks" value="pick" @checked($picked === 'pick')><span>Starting a date I pick<br><span class="muted">Uses the first date below.</span></span></label>
@error('weeks')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>
@endif

<div class="add-times add-two">
<x-field name="starts_on" type="date" label="First date" :value="$values['starts_on']" required />
<x-field name="ends_on" type="date" label="Last date (optional)" :value="$values['ends_on']" />
</div>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Add {{ $person ? $person->shortName() : 'regular' }}</button>
<a href="{{ $backUrl }}" class="btn">Cancel</a>
</div>
</form>
@endif
</section>
@endsection
