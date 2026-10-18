{{-- Screen 30 (week) and the day view, which is the same grid with one column. Below 700px wide, CSS swaps the grid for the list by day from screen 34; both are in the page so it works without JavaScript. --}}
@extends('layouts.app')

@php
    $days = $week->days();
    [$firstHour, $lastHour] = $week->hourRange();
    $isDay = $view === 'day';
    $route = $isDay ? 'calendar.day' : 'calendar.week';
    $title = \App\Calendar\TimeFormat::days($days);
    $dayForSwitch = in_array($today, $days, true) ? $today : $days[0];
    $emptyDays = collect($days)->filter(fn ($day) => $week->shiftsOn($day)->isEmpty())->map(fn ($day) => \Carbon\CarbonImmutable::parse($day)->format('D M j'));
@endphp

@section('title', 'Calendar: '.$title)
@section('main-class', 'cal')

@section('bar')
@include('calendar._toolbar', [
    'title' => $title,
    'todayUrl' => route($route, $areaQuery),
    'previousUrl' => route($route, ['date' => $previous] + $areaQuery),
    'nextUrl' => route($route, ['date' => $next] + $areaQuery),
    'previousLabel' => $isDay ? 'Previous day' : 'Previous week',
    'nextLabel' => $isDay ? 'Next day' : 'Next week',
    'dayUrl' => route('calendar.day', ['date' => $dayForSwitch] + $areaQuery),
    'weekUrl' => route('calendar.week', ['date' => $days[0]] + $areaQuery),
    'monthUrl' => route('calendar.month', ['month' => substr($days[0], 0, 7)] + $areaQuery),
])
@endsection

@section('content')
<div class="cal-body">
<aside class="cal-side">
@include('calendar._sidebar')
</aside>

<div class="cal-main">
@if (session('status'))
<p role="status" class="cal-status">{{ session('status') }}</p>
@endif
@if ($nothingShown)
<p class="cal-status">No areas are ticked. Tick at least one to see its shifts.</p>
@endif

<section class="cal-grid-wrap" aria-label="{{ $title }}">
<div class="cal-grid days-{{ count($days) }}">
<div></div>
@foreach ($days as $day)
@php($carbonDay = \Carbon\CarbonImmutable::parse($day))
<div class="dh{{ $day === $today ? ' today' : '' }}" @if ($day === $today) aria-current="date" @endif><span class="muted">{{ strtoupper($carbonDay->format('D')) }}</span><b>{{ $carbonDay->day }}</b><a href="{{ route('calendar.add', ['date' => $day] + $areaQuery) }}" class="add" aria-label="Add on {{ $carbonDay->format('l, F j') }}">Add</a></div>
@endforeach

<div class="hours" aria-hidden="true">@for ($hour = $firstHour; $hour < $lastHour; $hour++)<div>{{ \App\Calendar\TimeFormat::time(\Carbon\CarbonImmutable::today()->setTime($hour, 0)) }}</div>@endfor</div>
@foreach ($days as $day)
@php($shifts = $week->shiftsOn($day))
@php($columns = $week->columnsFor($shifts))
<div class="daycol" style="height: {{ ($lastHour - $firstHour) * \App\Calendar\CalendarWeek::HOUR_HEIGHT }}px">
@foreach ($shifts as $shift)
@include('calendar._shift-block')
@endforeach
</div>
@endforeach
</div>
</section>

<section class="cal-list" aria-label="Shifts by day">
@foreach ($days as $day)
@php($shifts = $week->shiftsOn($day))
@if ($shifts->isNotEmpty())
<div class="day"><span>{{ \Carbon\CarbonImmutable::parse($day)->format('D M j') }}</span><a href="{{ route('calendar.add', ['date' => $day] + $areaQuery) }}" class="muted">Add</a></div>
@foreach ($shifts as $shift)
@php($info = $week->describe($shift, $now))
<a class="{{ $info['classes'] }}" aria-label="{{ $info['label'] }}" href="{{ route('calendar.shift', [$shift] + $areaQuery) }}"><strong>{{ $info['time'] }}, {{ $info['area'] }}</strong>@foreach ($info['lines'] as $line)<span>{{ $line }}</span>@endforeach</a>
@endforeach
@endif
@endforeach
@if ($week->isEmpty())
<p class="muted">{{ $isDay ? 'Nothing on '.\Carbon\CarbonImmutable::parse($days[0])->format('l, F j').'.' : 'No shifts this week.' }}</p>
@elseif ($emptyDays->isNotEmpty())
<p class="muted">Days with nothing on them are left out. {{ $emptyDays->join(', ', ' and ') }} {{ $emptyDays->count() === 1 ? 'has' : 'have' }} no shifts.</p>
@endif
</section>
</div>

@hasSection('panel')
<aside class="cal-panel">
@yield('panel')
</aside>
@endif
</div>
@endsection
