{{-- The week and day sidebar: add, add a closure (for people who can close an area), the area filter, Needs attention (Your next shift for a volunteer), the border key and settings. $tools is true for anyone with a role. --}}
@if ($tools)
<a href="{{ route('calendar.add', ['date' => $date->toDateString()] + $areaQuery) }}" class="btn btn-primary cal-add">+ Add</a>
@endif
@if ($canClose)
<a href="{{ route('calendar.closures.create', $areaQuery) }}" class="btn cal-add">Add a closure</a>
@endif

<section class="cal-side-section">
<h2>Areas</h2>
@if ($areas->isEmpty())
<p class="muted">There are no areas yet.</p>
@else
<form method="GET" action="{{ route($view === 'day' ? 'calendar.day' : 'calendar.week') }}" class="cal-areas">
<input type="hidden" name="date" value="{{ $date->toDateString() }}">
{{-- Sent every time, so unticking every box shows nothing instead of everything. --}}
<input type="hidden" name="areas[]" value="">
@foreach ($areas as $area)
@can('update', $area)
{{-- The name is a link to the area's page, so the checkbox keeps its own label. --}}
<div class="check"><input type="checkbox" name="areas[]" value="{{ $area->id }}" aria-label="Show {{ $area->name }}" @checked(in_array($area->id, $shown, true))><span class="sw {{ $area->color->cssClass() }}"></span><a href="{{ route('calendar.areas.edit', ['area' => $area] + $areaQuery) }}">{{ $area->name }}</a></div>
@else
<label class="check"><input type="checkbox" name="areas[]" value="{{ $area->id }}" @checked(in_array($area->id, $shown, true))><span class="sw {{ $area->color->cssClass() }}"></span>{{ $area->name }}</label>
@endcan
@endforeach
<button type="submit" class="btn">Show</button>
</form>
@endif
@can('create', App\Models\Area::class)
<a href="{{ route('calendar.areas.create', $areaQuery) }}" class="muted">+ Add an area</a>
@endcan
</section>

@if ($tools)
<section class="cal-side-section attention">
<h2>Needs attention</h2>
@forelse ($needsAttention as $item)
<a href="{{ $item['url'] }}" class="muted attention-item{{ $item['urgent'] ? ' urgent' : '' }}"><strong>{{ $item['label'] }}</strong><br>{{ $item['detail'] }}</a>
@empty
<p class="muted">Nothing for the next {{ \App\Calendar\NeedsAttention::DAYS }} days.</p>
@endforelse
</section>
@else
<section class="cal-side-section attention">
<h2>Your next shift</h2>
@if ($nextShift)
@php($others = $nextShift->assignments->where('user_id', '!=', $viewer->id)->map(fn ($assignment) => $assignment->user->firstName())->values())
<a href="{{ route('calendar.shift', [$nextShift] + $areaQuery) }}" class="muted attention-item"><strong>{{ $nextShift->local_date->format('D M j') }}, {{ \App\Calendar\TimeFormat::range($nextShift->starts_at->setTimezone($organization->timezone), $nextShift->ends_at->setTimezone($organization->timezone)) }}</strong><br>{{ $nextShift->area->name }}@if ($others->isNotEmpty()), with {{ $others->join(', ', ' and ') }}@endif</a>
@else
<p class="muted">You're not on any shifts yet.</p>
@endif
</section>
@endif

<section class="muted cal-key">
@if ($tools)
<div>Dashed border: open places</div>
<div>Thick border: open within 24 hours</div>
@if ($hasOwnShift)
<div>Dark border: your shift</div>
@endif
@else
<div>Dashed border: needs someone</div>
<div>Dark border: your shift</div>
@endif
</section>

@can('manage-shadowing')
<a href="{{ route('settings', $areaQuery) }}" class="muted">Settings</a>
@endcan
