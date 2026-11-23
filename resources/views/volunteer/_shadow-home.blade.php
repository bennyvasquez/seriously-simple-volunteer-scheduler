{{-- Home for someone who isn't cleared yet (wireframe 6): their shadow progress, their next shadow shift, any other shifts they're on, and the open shadow spots. $done (ShadowProgress::done()), $required (the organization's required shadow shifts), $nextShadow (or null), $next (their other shifts in the next two weeks), $spots (HomeController::openShadowSpots()) and $timezone. --}}
<div class="progress">
<p><strong>Shadow shifts done: {{ $done }} of {{ $required }}</strong></p>
<div class="progress-bar" aria-hidden="true">
@for ($i = 0; $i < $required; $i++)
<span @class(['done' => $i < $done])></span>
@endfor
</div>
<p class="muted">After {{ $required }}, a coordinator reviews and clears you to take regular shifts.</p>
</div>

<section class="shift-section">
<h2>Your next shadow shift</h2>
@if ($nextShadow)
@include('volunteer._shift-card', ['shift' => $nextShadow, 'action' => 'release', 'tag' => $nextShadow->card_tag, 'note' => $nextShadow->shadowing_with === '' ? null : 'You\'ll shadow '.$nextShadow->shadowing_with])
@else
<p class="muted">You're not signed up for a shadow shift yet.</p>
@endif
@if ($next->isEmpty())
<a class="btn" href="{{ route('my-shifts') }}">All my shifts</a>
@endif
</section>

@if ($next->isNotEmpty())
<section class="shift-section">
<h2>Your other shifts</h2>
@foreach ($next as $shift)
@include('volunteer._shift-card', ['shift' => $shift, 'action' => 'release', 'tag' => $shift->card_tag])
@endforeach
<a class="btn" href="{{ route('my-shifts') }}">All my shifts</a>
</section>
@endif

@include('volunteer._shadow-spots')

<p class="muted">You'll be able to take shifts once a coordinator clears you.</p>
