{{-- The check step for several dates or the weekly slot (wireframe 40): the shifts that would be released, before anything changes. Confirming posts the same fields back with confirm=1; "Change the dates" goes back to the form with them kept. --}}
@extends('layouts.app')

@php
    $count = count($shifts);
@endphp

@section('title', 'Can\'t make it: check which shifts')

@section('content')
<h1>{{ match (true) { $count === 0 => 'Give up your weekly slot', $count === 1 => 'You\'d be off this shift', default => 'You\'d be off these '.$count.' shifts' } }}</h1>
@if ($range)<p class="muted">{{ $range }}</p>@endif

@if ($count > 0)
<ul class="shift-list">
@foreach ($shifts as $listed)
<li class="card"><span class="when">{{ $listed['date'] }}</span><span>{{ $listed['what'] }}</span></li>
@endforeach
</ul>
@else
<p>None of those dates are on the calendar yet, so nobody needs to be asked to cover them.</p>
@endif

@if ($lastOnCalendar)<p class="muted">Shifts after {{ $lastOnCalendar }} aren't on the calendar yet, so they aren't included.</p>@endif
@if ($regularLine)<p class="muted">{{ $regularLine }}</p>@endif

@if ($note !== null)
<div class="note-box">
<p class="muted">Your note for whoever covers</p>
<p>{{ $note }}</p>
</div>
@endif

@if ($count > 0)
<p class="muted">You're off {{ $count === 1 ? 'it' : 'all '.$count }} as soon as you confirm. We'll ask other volunteers to cover {{ $count === 1 ? 'it' : 'each one' }}.</p>
@endif

<form method="POST" action="{{ route('shifts.release', $shift) }}">
@csrf
@foreach ($hidden as $name => $value)
<input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
<button type="submit" class="btn btn-primary" name="confirm" value="1">{{ match (true) { $count === 0 => 'Give up my weekly slot', $count === 1 => 'Release this shift', default => 'Release these '.$count.' shifts' } }}</button>
<button type="submit" class="btn" name="change" value="1">Change the dates</button>
</form>
@endsection
