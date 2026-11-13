{{-- Ending a regular's time on a weekly slot, beside the week. When the last date is in the past and the person is on shifts after it, the same page comes back as a confirm step listing those shifts, so it works without JavaScript. --}}
@extends('calendar.week')

@php
    $dateParam = $date->toDateString();
    $first = $person->firstName();
    $shiftId = $slot->shifts()->where('local_date', $dateParam)->value('id');
    $closeUrl = $shiftId ? route('calendar.shift', [$shiftId] + $areaQuery) : route('calendar.week', ['date' => $dateParam] + $areaQuery);
    $formUrl = route('calendar.slots.regulars.end.store', [$slot, $regular]);
    $count = $pastShifts?->count() ?? 0;
@endphp

@section('panel')
<section aria-label="End a regular" class="add-panel">
<div class="add-head">
<h2>End {{ $personName }}'s time as a regular</h2>
<a href="{{ $closeUrl }}" class="btn btn-icon" aria-label="Close">x</a>
</div>
<div>
<p><b>{{ $slotText }}</b></p>
<p>{{ $slot->area->name }}</p>
</div>

@if ($count > 0)
<form method="POST" action="{{ $formUrl }}">
@csrf
<input type="hidden" name="date" value="{{ $dateParam }}">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<input type="hidden" name="last_date" value="{{ $lastDate }}">
<input type="hidden" name="past_confirmed" value="1">
<p role="alert"><b>{{ $first }} is on {{ $count }} past {{ $count === 1 ? 'shift' : 'shifts' }} after {{ \Carbon\CarbonImmutable::parse($lastDate, 'UTC')->format('l, F j') }}. Ending their time as a regular removes them from {!! $count === 1 ? "it too, so it doesn't" : "those too, so they don't" !!} count as worked:</b></p>
@foreach ($pastShifts as $listed)
<p>{{ $listed->whenText() }}@if ($listed->cancelled_at) (cancelled)@endif</p>
@endforeach
<div class="add-actions">
<button type="submit" class="btn btn-primary">Remove {{ $first }} from {{ $count }} past {{ $count === 1 ? 'shift' : 'shifts' }} and end their time as a regular</button>
<a href="{{ route('calendar.slots.regulars.end', [$slot, $regular, 'date' => $dateParam, 'last_date' => $lastDate] + $areaQuery) }}" class="btn">Change the date</a>
</div>
</form>
@else
<form method="POST" action="{{ $formUrl }}">
@csrf
<input type="hidden" name="date" value="{{ $dateParam }}">
@foreach ($areaQuery['areas'] ?? [] as $areaId)
<input type="hidden" name="areas[]" value="{{ $areaId }}">
@endforeach
<x-field name="last_date" type="date" label="Last date they work" :value="$lastDate" hint="They're removed from every shift of this slot after that date. They're emailed about future shifts." required />
<div class="add-actions">
<button type="submit" class="btn btn-primary">End their time as a regular</button>
<a href="{{ $closeUrl }}" class="btn">Cancel</a>
</div>
</form>
@endif
</section>
@endsection
