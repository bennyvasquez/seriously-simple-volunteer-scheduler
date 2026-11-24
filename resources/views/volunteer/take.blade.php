{{-- Taking an open shift. $mode is "cover" (wireframe 44: a cover request taken for its date only, no question), "claim" (wireframe 3: "How long do you want it?" with nothing picked, or no question when the date is the only choice), "taken" (wireframe 46: someone already took it, with other open shifts) or "refused" (cancelled, started, or something else the person can't get past). --}}
@extends('layouts.app')

@php
    $heading = match ($mode) {
        'refused' => $refusal,
        'taken' => 'Someone already took this one',
        'cover' => $tookBack ? 'Can you make it after all?' : 'Can you cover this one?',
        default => count($choices) > 1 ? 'How long do you want it?' : 'Can you take this one?',
    };
    $from = $releaser ?? 'Someone';
@endphp

@section('title', $heading)

@section('content')
@include('volunteer._status')
@if ($mode === 'taken')
<div role="status" class="result">
<h1>{{ $heading }}</h1>
<p>{{ $card['area'] }} on {{ $card['date'] }} is covered. Thanks for offering!</p>
</div>
@include('volunteer._open-shifts', ['heading' => 'Other open shifts you can take'])
<a href="{{ route('home') }}" class="btn">Back to home</a>
@elseif ($mode === 'refused')
<h1>{{ $heading }}</h1>
<div class="shift-head">
<p class="when">{{ $card['date'] }}</p>
<p>{{ $card['what'] }}</p>
</div>
<a href="{{ route('home') }}" class="btn">Back to home</a>
@else
@if ($mode === 'cover' || count($choices) === 1)
<h1>{{ $heading }}</h1>
@endif
<div class="shift-head">
<p class="when">{{ $card['date'] }}</p>
<p>{{ $card['what'] }}</p>
@if ($mode === 'cover')
<p class="muted">{{ $tookBack ? 'You released this shift. You\'d take this date back.' : $from.' can\'t make it. You\'d take this date only.' }}</p>
@else
@if ($cover && ! $tookBack)<p class="muted">{{ $from }} can't make it.</p>@endif
<p class="muted">Needs {{ $openPlaces }} {{ $openPlaces === 1 ? 'person' : 'people' }}</p>
@if (count($choices) > 1)<p class="muted">This weekly slot has no regular right now.</p>@else<p class="muted">You'd take this date only.</p>@endif
@endif
@if ($shadows !== [])<p class="muted">Shadowing: {{ App\Http\Controllers\Volunteer\ReleaseController::names($shadows) }}</p>@endif
</div>

@if ($cover && $cover->note !== null && ! $tookBack)
<div class="note-box">
<p class="muted">{{ $releaser ? 'Note from '.$releaser : 'Note for whoever covers' }}</p>
<p>{{ $cover->note }}</p>
</div>
@endif

<form method="POST" action="{{ route('shifts.take', $shift) }}">
@csrf
@if (count($choices) > 1)
<fieldset class="choices options" @error('how') aria-describedby="how-error" @enderror>
<legend><h1>{{ $heading }}</h1></legend>
@error('how')<span class="field-error" id="how-error">{{ $message }}</span>@enderror
@foreach ($choices as $choice)
@php
    [$label, $hint] = match ($choice) {
        App\Enums\TakeHow::JustThisDate => ['Just '.$card['date'], null],
        App\Enums\TakeHow::EveryWeek => [($slotEveryOtherWeek ? 'Every other ' : 'Every ').$weekday, 'You\'d become this slot\'s regular.'],
        App\Enums\TakeHow::EveryOtherWeek => ['Every other '.$weekday, 'Starting '.$card['date'].'. Someone else can take the weeks in between.'],
    };
@endphp
<label class="opt"><input type="radio" name="how" value="{{ $choice->value }}" @checked($how === $choice->value)><span><strong>{{ $label }}</strong>@if ($hint)<br><span class="muted">{{ $hint }}</span>@endif</span></label>
@endforeach
</fieldset>
@else
<input type="hidden" name="how" value="date">
@error('how')<p class="field-error" role="alert">{{ $message }}</p>@enderror
@endif
<button type="submit" class="btn btn-primary">Take this shift</button>
<a href="{{ route('home') }}" class="btn">Not this time</a>
</form>
@endif
@endsection
