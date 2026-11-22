{{-- One shift as a card. $shift, $timezone, $action ('release' or 'take'), $tag and $note (both optional), $strong (optional). A cancelled shift shows its reason as the note and has no button: there's nothing to release or take. --}}
@php
    $start = $shift->starts_at->setTimezone($timezone);
    $date = $start->format('l, F j');
    $label = $action === 'take' ? 'Take this shift' : 'Can\'t make it';
    $cancelled = $shift->cancelled_at !== null;
    $note = $cancelled ? $shift->cancel_reason : ($note ?? null);
@endphp
<div class="card{{ ($strong ?? false) ? ' card-strong' : '' }}">
<div class="card-top"><span class="when">{{ $date }}</span>@if ($tag ?? null)<span class="tag{{ ($strong ?? false) ? ' tag-strong' : '' }}">{{ $tag }}</span>@endif</div>
<div>{{ $shift->area->name }}, {{ App\Calendar\TimeFormat::range($start, $shift->ends_at->setTimezone($timezone)) }}</div>
@if ($note)
<div class="muted">{{ $note }}</div>
@endif
@unless ($cancelled)
<a class="btn{{ $action === 'take' ? ' btn-primary' : '' }}" href="{{ route($action === 'take' ? 'shifts.take' : 'shifts.release', $shift) }}" aria-label="{{ $label }}, {{ $shift->area->name }}, {{ $date }}">{{ $label }}</a>
@endunless
</div>
