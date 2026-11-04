{{-- The week and day sidebar: add, add a closure (for people who can close an area), the area filter, Needs attention, the border key and settings. --}}
<a href="{{ route('calendar.add', ['date' => $date->toDateString()] + $areaQuery) }}" class="btn btn-primary cal-add">+ Add</a>
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

<section class="cal-side-section attention">
<h2>Needs attention</h2>
@forelse ($needsAttention as $item)
<a href="{{ $item['url'] }}" class="muted attention-item{{ $item['urgent'] ? ' urgent' : '' }}"><strong>{{ $item['label'] }}</strong><br>{{ $item['detail'] }}</a>
@empty
<p class="muted">Nothing for the next {{ \App\Calendar\NeedsAttention::DAYS }} days.</p>
@endforelse
</section>

<section class="muted cal-key">
<div>Dashed border: open places</div>
<div>Thick border: open within 24 hours</div>
</section>

@can('manage-settings')
<a href="{{ route('settings', $areaQuery) }}" class="muted">Settings</a>
@endcan
