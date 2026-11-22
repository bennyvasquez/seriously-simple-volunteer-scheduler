{{-- "You're off" (wireframe 41), from the release's session flash: $released has the shifts (date, area, what, shadowSpot), regularCount, the shadows told, the regular line and, when they gave up their weekly slot, its weekday. --}}
@extends('layouts.app')

@php
    $shifts = $released['shifts'];
    $count = count($shifts);
    $regularCount = $released['regularCount'];
    $shadows = $released['shadows'];
    $heading = match (true) {
        $count === 0 => 'You\'re no longer the '.$released['slotWeekday'].' regular',
        $count === 1 => 'You\'re off '.$shifts[0]['date'],
        default => 'You\'re off '.$count.' shifts',
    };
@endphp

@section('title', $heading)

@section('content')
<div role="status" class="result">
<h1>{{ $heading }}</h1>
@if ($count > 1)
<ul class="plain-list">
@foreach ($shifts as $listed)
<li>{{ $listed['shadowSpot'] ? $listed['date'].': shadow spot on '.$listed['area'] : $listed['date'].', '.$listed['what'] }}</li>
@endforeach
</ul>
@endif
@if ($regularCount === 1 && $count === 1)
<p>We've asked other volunteers to cover your {{ $shifts[0]['area'] }} shift. We'll email you when someone takes it.</p>
@elseif ($regularCount > 0)
<p>We've asked other volunteers to cover them. We'll email you when someone takes one.</p>
@endif
@if ($regularCount < $count)
<p>{{ $count === 1 ? 'You\'ve left your shadow spot.' : 'You\'ve left your shadow spots.' }} We've let the coordinators know.</p>
@endif
@if ($shadows !== [])
<p class="muted">{{ App\Http\Controllers\Volunteer\ReleaseController::names($shadows) }} {{ count($shadows) === 1 ? 'was' : 'were' }} going to shadow you. We've told them their shadow {{ count($shadows) === 1 ? 'spot is' : 'spots are' }} on hold until someone covers.</p>
@endif
@if ($released['regularLine'])<p class="muted">{{ $released['regularLine'] }}</p>@endif
@if ($regularCount === 1)
<p class="muted">Changed your mind? While it's still open, it's under "Open shifts you can take" and you can take it back.</p>
@elseif ($regularCount > 1)
<p class="muted">Changed your mind? While they're still open, they're under "Open shifts you can take" and you can take them back.</p>
@endif
</div>

<section class="shift-section">
<h2>Your next shifts</h2>
@forelse ($next as $shift)
@include('volunteer._shift-card', ['shift' => $shift, 'action' => 'release', 'tag' => $shift->card_tag])
@empty
<p class="muted">You're not on any shifts in the next two weeks.</p>
@endforelse
</section>

<a href="{{ route('home') }}" class="btn">Back to home</a>
@endsection
