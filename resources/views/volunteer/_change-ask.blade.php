{{-- Home's question for a time change the person hasn't answered ($notice is ChangeNoticeController::summary() plus `batch`). "Still works for me" posts to the answer route and comes back to Home with the thanks. --}}
<section class="card card-strong shift-section" aria-label="{{ $notice['heading'] }}">
<h2>{{ $notice['heading'] }}</h2>
@if ($notice['count'] === 1 || $notice['same'])
<p>Now {{ $notice['now'] }}, was {{ $notice['was'] }}.@if ($notice['count'] > 1) Starting {{ $notice['date'] }}.@endif</p>
@else
@foreach ($notice['lines'] as $line)
<p>{{ $line['date'] }}: now {{ $line['now'] }}, was {{ $line['was'] }}.</p>
@endforeach
@endif
<form method="POST" action="{{ route('changes.confirm', $notice['batch']) }}">
@csrf
<input type="hidden" name="from" value="home">
<div class="add-actions">
<button type="submit" class="btn btn-primary">Still works for me</button>
<a href="{{ route('shifts.release', $notice['earliest']) }}" class="btn">I can't make the new time</a>
</div>
</form>
</section>
