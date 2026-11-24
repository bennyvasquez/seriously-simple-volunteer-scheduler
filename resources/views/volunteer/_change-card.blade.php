{{-- The card on the answer page: the new and old times (ChangeNoticeController::summary()). One shift, or several with the same times, shows "New time", "Was" and the date; several with different times list each date. $changedBy is optional and shown when set. --}}
<div class="card card-strong">
@if ($summary['count'] === 1)
<div class="card-top"><span class="when">New time</span></div>
<div>{{ $summary['date'] }}, {{ $summary['now'] }}</div>
<div class="muted">Was <s>{{ $summary['was'] }}</s></div>
@elseif ($summary['same'])
<div class="card-top"><span class="when">New time</span></div>
<div>{{ $summary['now'] }}</div>
<div class="muted">Was <s>{{ $summary['was'] }}</s></div>
<div class="muted">Starting {{ $summary['date'] }}, {{ $summary['count'] }} shifts</div>
@else
<div class="card-top"><span class="when">New times</span></div>
@foreach ($summary['lines'] as $line)
<div>{{ $line['date'] }}, {{ $line['now'] }} <span class="muted">(was <s>{{ $line['was'] }}</s>)</span></div>
@endforeach
@endif
@if ($summary['changedBy'] && ($withChangedBy ?? true))
<div class="muted">Changed by {{ $summary['changedBy'] }}</div>
@endif
</div>
