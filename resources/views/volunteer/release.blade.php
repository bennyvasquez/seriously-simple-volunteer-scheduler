{{-- "Can't make it" (wireframe 2). Nothing is picked to start with. Several dates and the weekly slot go to the check step first; a shadow just confirms leaving the shadow spot. --}}
@extends('layouts.app')

@section('title', 'Can\'t make it')

@php
    $noteError = $errors->first('note');
@endphp

@section('content')
@include('volunteer._status')
<p><a href="{{ route('home') }}">Back to home</a></p>
<h1>Can't make it</h1>

<div class="shift-head">
<p class="when">{{ $card['date'] }}</p>
<p>{{ $card['what'] }}</p>
@if ($regular && ! $shadow)<p class="muted">Your weekly {{ $regular->weeklySlot->weekdayName() }} slot</p>@endif
</div>

<form method="POST" action="{{ route('shifts.release', $shift) }}">
@csrf
@if ($shadow)
<p>You'll leave your shadow spot on {{ $card['date'] }}. We'll let the coordinators know.</p>
@error('scope')<p class="field-error" role="alert">{{ $message }}</p>@enderror
@else
<fieldset class="choices options" @error('scope') aria-describedby="scope-error" @enderror>
<legend>Which shifts can't you make?</legend>
@error('scope')<span class="field-error" id="scope-error" role="alert">{{ $message }}</span>@enderror
<label class="opt"><input type="radio" name="scope" value="one" @checked($values['scope'] === 'one')><span><strong>Just {{ $card['date'] }}</strong>@if ($regular)<br><span class="muted">You stay on your other {{ $regular->weeklySlot->weekdayName() }}s.</span>@endif</span></label>
<div class="opt-group">
<label class="opt"><input type="radio" name="scope" value="range" @checked($values['scope'] === 'range') aria-describedby="range-hint"><span><strong>Several dates (I'm away)</strong><br><span class="muted" id="range-hint">Pick the first and last day you're gone.</span></span></label>
<div class="opt-fields">
<div class="field"><label for="from">From</label><input type="date" name="from" id="from" value="{{ $values['from'] }}" @error('dates') aria-invalid="true" aria-describedby="dates-error" @enderror></div>
<div class="field"><label for="to">To</label><input type="date" name="to" id="to" value="{{ $values['to'] }}" @error('dates') aria-invalid="true" aria-describedby="dates-error" @enderror></div>
</div>
@error('dates')<span class="field-error" id="dates-error" role="alert">{{ $message }}</span>@enderror
</div>
@if ($regular)
<div class="opt-group">
<label class="opt"><input type="radio" name="scope" value="slot" @checked($values['scope'] === 'slot') aria-describedby="slot-hint"><span><strong>Give up my weekly {{ $regular->weeklySlot->weekdayName() }} slot</strong><br><span class="muted" id="slot-hint">Someone else can take it over.</span></span></label>
<div class="opt-fields">
<div class="field"><label for="starting">Starting</label><input type="date" name="starting" id="starting" value="{{ $values['starting'] }}" @error('starting') aria-invalid="true" aria-describedby="starting-error" @enderror></div>
</div>
@error('starting')<span class="field-error" id="starting-error" role="alert">{{ $message }}</span>@enderror
</div>
@endif
</fieldset>
@endif

<div class="field">
<label for="note">Note for whoever covers (optional)</label>
<textarea name="note" id="note" rows="2" maxlength="500" aria-describedby="note-hint{{ $noteError ? ' note-error' : '' }}" @if ($noteError) aria-invalid="true" @endif>{{ $values['note'] }}</textarea>
<span class="field-hint" id="note-hint">Anything they should know. Up to 500 characters.</span>
@if ($noteError)<span class="field-error" id="note-error" role="alert">{{ $noteError }}</span>@endif
</div>

@unless ($shadow)
<p class="muted">You're off the shift as soon as you confirm.
@if (! $needsCover){{ $shift->area->name }} still has the people it needs, so nobody else will be asked.@else We'll ask other volunteers to cover it right away, and tell your lead if it's still open 24 hours before.@endif
@if ($shadowNames !== []) {{ App\Http\Controllers\Volunteer\ReleaseController::names($shadowNames) }} {{ count($shadowNames) === 1 ? 'was' : 'were' }} shadowing you, so we'll let them know too.@endif</p>
@endunless

<button type="submit" class="btn btn-primary">{{ $shadow ? 'Leave this shadow spot' : 'Release this shift' }}</button>
<a href="{{ route('home') }}" class="btn">Keep it</a>
</form>
@endsection
