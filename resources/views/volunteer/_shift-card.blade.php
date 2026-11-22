{{-- One shift as a card. $shift, $timezone, $action ('release' or 'take'), $tag and $note (both optional), $strong (optional). --}}
@php
    $start = $shift->starts_at->setTimezone($timezone);
    $date = $start->format('l, F j');
    $label = $action === 'take' ? 'Take this shift' : 'Can\'t make it';
@endphp
<div class="card{{ ($strong ?? false) ? ' card-strong' : '' }}">
<div class="card-top"><span class="when">{{ $date }}</span>@if ($tag ?? null)<span class="tag{{ ($strong ?? false) ? ' tag-strong' : '' }}">{{ $tag }}</span>@endif</div>
<div>{{ $shift->area->name }}, {{ App\Calendar\TimeFormat::range($start, $shift->ends_at->setTimezone($timezone)) }}</div>
@if ($note ?? null)
<div class="muted">{{ $note }}</div>
@endif
<a class="btn{{ $action === 'take' ? ' btn-primary' : '' }}" href="{{ url('/shifts/'.$shift->id.'/'.$action) }}" aria-label="{{ $label }}, {{ $shift->area->name }}, {{ $date }}">{{ $label }}</a>
</div>
