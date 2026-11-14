{{-- One closure label on the week, day or month view. A label for one closure is a link to change it for people who can, and text for everyone else. A label shared by several areas' closures stays text, followed by "Change:" and one link per area the viewer can change, each opening that area's closure and named for screen readers as "Change the {area} closure for {reason}". --}}
@php($editable = array_values(array_filter($closure['closures'], fn (array $one) => in_array($one['area_id'], $closableAreaIds ?? [], true))))
@if (count($closure['closures']) === 1 && $editable !== [])
<a href="{{ route('calendar.closures.edit', ['closure' => $editable[0]['closure_id']] + $areaQuery) }}" class="closed">{{ $closure['label'] }}</a>
@elseif ($editable === [])
<span class="closed">{{ $closure['label'] }}</span>
@else
<span class="closed">{{ $closure['label'] }}. Change: @foreach ($editable as $one)<a href="{{ route('calendar.closures.edit', ['closure' => $one['closure_id']] + $areaQuery) }}" aria-label="Change the {{ $one['name'] }} closure for {{ $one['reason'] }}">{{ $one['name'] }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</span>
@endif
