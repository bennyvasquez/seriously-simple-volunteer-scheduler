{{-- The week and day sidebar: add, the area filter, Needs attention, the border key and settings. Links to screens built later only show once their routes exist. --}}
<a href="{{ route('calendar.add', ['date' => $date->toDateString()] + $areaQuery) }}" class="btn btn-primary cal-add">+ Add</a>

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
<label class="check"><input type="checkbox" name="areas[]" value="{{ $area->id }}" @checked(in_array($area->id, $shown, true))><span class="sw {{ $area->color->cssClass() }}"></span>{{ $area->name }}</label>
@endforeach
<button type="submit" class="btn">Show</button>
</form>
@endif
@if (Route::has('calendar.areas.create'))
@can('create', App\Models\Area::class)
<a href="{{ route('calendar.areas.create') }}" class="muted">+ Add an area</a>
@endcan
@endif
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

@if (Route::has('settings'))
@can('manage-settings')
<a href="{{ route('settings') }}" class="muted">Settings</a>
@endcan
@endif
