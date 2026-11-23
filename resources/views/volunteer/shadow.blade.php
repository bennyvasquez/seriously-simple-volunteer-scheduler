{{-- Signing up for a shadow spot. With no $refusal it asks "Can you shadow this one?" with one "Shadow this shift" button. With a $refusal it says why, and lists other open shadow spots when $spots isn't null (someone not cleared yet), the way "Someone already took this one" lists other open shifts. --}}
@extends('layouts.app')

@php
    $heading = $refusal ?? 'Can you shadow this one?';
@endphp

@section('title', $heading)

@section('content')
<h1>{{ $heading }}</h1>
<div class="shift-head">
<p class="when">{{ $card['date'] }}</p>
<p>{{ $card['what'] }}</p>
@if ($refusal === null)
<p class="muted">You'll shadow {{ $shadowingWith }}</p>
@endif
</div>

@if ($refusal === null)
<form method="POST" action="{{ route('shifts.shadow', $shift) }}">
@csrf
<button type="submit" class="btn btn-primary">Shadow this shift</button>
<a href="{{ route('home') }}" class="btn">Not this time</a>
</form>
@else
@if ($spots !== null)
@include('volunteer._shadow-spots', ['heading' => 'Other shadow spots open'])
@endif
<a href="{{ route('home') }}" class="btn">Back to home</a>
@endif
@endsection
