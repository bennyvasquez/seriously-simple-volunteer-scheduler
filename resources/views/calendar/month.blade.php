{{-- Screen 33: the month, with each day's shift count per area, open places and closures. A day's count opens its week. --}}
@extends('layouts.app')

@php($title = $calendar->month->format('F Y'))

@section('title', 'Calendar: '.$title)
@section('main-class', 'cal')

@section('bar')
@include('calendar._toolbar', [
    'view' => 'month',
    'title' => $title,
    'todayUrl' => route('calendar.month', $areaQuery),
    'previousUrl' => route('calendar.month', ['month' => $previous] + $areaQuery),
    'nextUrl' => route('calendar.month', ['month' => $next] + $areaQuery),
    'previousLabel' => 'Previous month',
    'nextLabel' => 'Next month',
    'dayUrl' => route('calendar.day', ['date' => $date] + $areaQuery),
    'weekUrl' => route('calendar.week', ['date' => $date] + $areaQuery),
    'monthUrl' => route('calendar.month', ['month' => $calendar->month->format('Y-m')] + $areaQuery),
])
@endsection

@section('content')
<div class="cal-month">
<div class="cal-month-actions">
<a href="{{ route('calendar.add', ['date' => $date] + $areaQuery) }}" class="btn btn-primary">+ Add</a>
@if ($canClose)
<a href="{{ route('calendar.closures.create', $areaQuery) }}" class="btn">Add a closure</a>
@endif
<span class="muted">Each day shows how many shifts it has. Pick a day to see it in the week view.</span>
</div>
@if ($nothingShown)
<p class="cal-status">No areas are ticked. Tick at least one to see its shifts.</p>
@endif
<div class="month-scroll">
<div class="month-grid">
@foreach (['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'] as $weekday)
<div class="wd">{{ $weekday }}</div>
@endforeach
@foreach ($calendar->weeks() as $weekDays)
@foreach ($weekDays as $day)
<div class="cell{{ $day['date'] === $today ? ' today' : '' }}" @if ($day['date'] === $today) aria-current="date" @endif>
@if ($day['inMonth'])
<a href="{{ route('calendar.week', ['date' => $day['date']] + $areaQuery) }}" class="num" aria-label="Week of {{ \Carbon\CarbonImmutable::parse($day['date'])->format('l, F j') }}">{{ $day['label'] }}</a>
@foreach ($day['closures'] as $closure)
<span class="closed">{{ $closure }}</span>
@endforeach
@foreach ($day['counts'] as $count)
<a href="{{ route('calendar.week', ['date' => $day['date']] + $areaQuery) }}" class="cnt {{ $count['area']->color->cssClass() }}">{{ $count['text'] }}</a>
@endforeach
@foreach ($day['cancelled'] as $text)
<span class="cancelled-count">{{ $text }}</span>
@endforeach
@if ($day['open'] > 0)
<span class="op">{{ $day['open'] }} open</span>
@endif
@else
<span class="num out">{{ $day['label'] }}</span>
@endif
</div>
@endforeach
@endforeach
</div>
</div>
</div>
@endsection
