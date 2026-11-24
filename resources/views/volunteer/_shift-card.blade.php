{{-- One shift as a card. $shift, $timezone, $action ('release', 'take' or 'shadow'), $tag and $note (both optional), $strong (optional). A cancelled shift shows its reason as the note and has no button: there's nothing to release or take. --}}
@php
    $start = $shift->starts_at->setTimezone($timezone);
    $date = $start->format('l, F j');
    [$label, $route] = match ($action) {
        'take' => ['Take this shift', 'shifts.take'],
        'shadow' => ['Shadow this shift', 'shifts.shadow'],
        default => ['Can\'t make it', 'shifts.release'],
    };
    $cancelled = $shift->cancelled_at !== null;
    $note = $cancelled ? $shift->cancel_reason : ($note ?? null);
@endphp
<div class="card{{ ($strong ?? false) ? ' card-strong' : '' }}">
<div class="card-top"><span class="when">{{ $date }}</span>@if ($tag ?? null)<span class="tag{{ ($strong ?? false) ? ' tag-strong' : '' }}">{{ $tag }}</span>@endif @if (($shift->has_open_notice ?? false) && ! $cancelled)<span class="tag tag-strong">Time changed</span>@endif</div>
<div>{{ $shift->area->name }}, {{ App\Calendar\TimeFormat::range($start, $shift->ends_at->setTimezone($timezone)) }}</div>
@if ($note)
<div class="muted">{{ $note }}</div>
@endif
@unless ($cancelled)
<a class="btn{{ $action === 'release' ? '' : ' btn-primary' }}" href="{{ route($route, $shift) }}" aria-label="{{ $label }}, {{ $shift->area->name }}, {{ $date }}">{{ $label }}</a>
@endunless
</div>
