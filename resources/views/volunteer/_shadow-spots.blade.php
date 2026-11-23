{{-- "Shadow spots open". $spots is HomeController::openShadowSpots(), $timezone the organization's, and $heading (optional) replaces the heading. --}}
<section class="shift-section">
<h2>{{ $heading ?? 'Shadow spots open' }}</h2>
@forelse ($spots as $shift)
@include('volunteer._shift-card', ['shift' => $shift, 'action' => 'shadow', 'note' => 'With '.$shift->shadowing_with])
@empty
<p class="muted">No shadow spots open in the next two weeks.</p>
@endforelse
</section>
