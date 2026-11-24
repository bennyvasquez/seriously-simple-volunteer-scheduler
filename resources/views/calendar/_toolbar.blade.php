{{-- The calendar's top bar: today, previous and next, the title, and the day, week and month switch. --}}
<div class="cal-bar">
<div class="cal-bar-group">
<div class="bar-name">{{ $organization->name }}</div>
<a href="{{ $todayUrl }}" class="btn">Today</a>
<a href="{{ $previousUrl }}" class="btn btn-icon" aria-label="{{ $previousLabel }}">&lt;</a>
<a href="{{ $nextUrl }}" class="btn btn-icon" aria-label="{{ $nextLabel }}">&gt;</a>
<h1>{{ $title }}</h1>
</div>
<div class="cal-bar-group">
<nav class="seg" aria-label="Calendar view"><a href="{{ $dayUrl }}" @if ($view === 'day') aria-current="page" @endif>Day</a><a href="{{ $weekUrl }}" @if ($view === 'week') aria-current="page" @endif>Week</a><a href="{{ $monthUrl }}" @if ($view === 'month') aria-current="page" @endif>Month</a></nav>
@can('viewAny', App\Models\User::class)<a href="{{ route('people.index') }}" class="btn">People</a>@endcan
@can('view-change-log')<a href="{{ route('change-log.index') }}" class="btn">Change log</a>@endcan
@can('manage-emails')<a href="{{ route('emails.index') }}" class="btn">Emails we send</a>@endcan
</div>
</div>
