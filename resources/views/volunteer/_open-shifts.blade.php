{{-- "Open shifts you can take". $open is HomeController::openShifts(), $timezone the organization's. --}}
<section class="shift-section">
<h2>Open shifts you can take</h2>
@forelse ($open as $shift)
@include('volunteer._shift-card', [
    'shift' => $shift,
    'action' => 'take',
    'strong' => $shift->cover_names !== [],
    'tag' => $shift->cover_names !== [] ? 'Needs cover' : ($shift->has_no_regular ? 'No regular' : null),
    'note' => $shift->cover_names !== [] ? collect($shift->cover_names)->join(', ', ' and ').' can\'t make it' : null,
])
@empty
<p class="muted">No open shifts in the next two weeks.</p>
@endforelse
</section>
