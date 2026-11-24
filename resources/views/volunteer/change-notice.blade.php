{{-- The answer page for a change notice (wireframes 57 and 58). $mode is open, thanks, confirmed, replaced, changed, cancelled, started or gone; $summary is ChangeNoticeController::summary(); $batch, $cantUrl (Can't make it for the earliest shift) and $shiftUrl (the shift on the schedule). --}}
@extends('layouts.app')

@php
    $heading = match ($mode) {
        'open' => $summary['heading'],
        'thanks' => 'Thanks. You\'re still on at the new time.',
        'confirmed' => 'You already said the new time works.',
        'replaced' => 'This shift has changed again since that email.',
        'changed' => 'This shift has changed since that email.',
        'cancelled' => 'This shift was cancelled.',
        'started' => 'This shift has already started.',
        default => 'You\'re not on this shift any more.',
    };
@endphp

@section('title', $heading)

@section('content')
@include('volunteer._status')
@if ($mode === 'thanks')<div role="status">@endif
<h1>{{ $heading }}</h1>
@if ($mode === 'thanks')</div>@endif
@if ($mode === 'open')
@include('volunteer._change-card')
<form method="POST" action="{{ route('changes.confirm', $batch) }}">
@csrf
<p><b>Does the new time still work for you?</b></p>
<div class="add-actions">
<button type="submit" class="btn btn-primary">Still works for me</button>
<a href="{{ $cantUrl }}" class="btn">I can't make the new time</a>
</div>
</form>
<p class="muted">If you can't make the new time, we'll ask other volunteers to cover it, the same as when you use Can't make it.</p>
@elseif ($mode === 'thanks')
@include('volunteer._change-card', ['withChangedBy' => false])
<a href="{{ route('home') }}" class="btn btn-primary">Back to home</a>
@elseif ($mode === 'confirmed')
@include('volunteer._change-card', ['withChangedBy' => false])
<div class="add-actions">
<a href="{{ $cantUrl }}" class="btn">I can't make it after all</a>
<a href="{{ route('home') }}" class="btn btn-primary">Back to home</a>
</div>
@elseif ($mode === 'replaced')
<p>Check the newest email, or open the shift for its current time.</p>
<a href="{{ $shiftUrl }}" class="btn btn-primary">Open the shift</a>
@elseif ($mode === 'changed')
<p>You're on this shift at its current time. This email's question doesn't apply any more.</p>
<a href="{{ $shiftUrl }}" class="btn btn-primary">Open the shift</a>
@else
<a href="{{ route('home') }}" class="btn btn-primary">Back to home</a>
@endif
@endsection
