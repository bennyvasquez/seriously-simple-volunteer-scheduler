{{-- One closure label on the week, day or month view: a link to change the closure for people who can, plain text for everyone else. --}}
@if (in_array($closure['area_id'], $closableAreaIds ?? [], true))
<a href="{{ route('calendar.closures.edit', ['closure' => $closure['closure_id']] + $areaQuery) }}" class="closed">{{ $closure['label'] }}</a>
@else
<span class="closed">{{ $closure['label'] }}</span>
@endif
