{{-- One shift on the week grid. Overlapping shifts share the day's width. --}}
@php
    $info = $week->describe($shift, $now);
    [$column, $columnCount] = $columns[$shift->id] ?? [0, 1];
    $style = 'top: '.$week->top($shift).'px; height: '.$week->height($shift).'px;';
    if ($columnCount > 1) {
        $style .= ' left: calc('.round($column * 100 / $columnCount, 3).'% + 2px); right: calc('.round(($columnCount - $column - 1) * 100 / $columnCount, 3).'% + 2px);';
    }
@endphp
<a class="{{ $info['classes'] }}" aria-label="{{ $info['label'] }}" href="{{ route('calendar.shift', [$shift] + $areaQuery) }}" style="{{ $style }}"><span class="t">{{ $info['time'] }}</span><span>{{ $info['area'] }}</span>@foreach ($info['lines'] as $line)<span>{{ $line }}</span>@endforeach</a>
