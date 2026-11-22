@extends('layouts.app')

@section('title', 'All my shifts')

@section('content')
<p><a href="{{ route('home') }}">Back to home</a></p>
<h1>All my shifts</h1>

@forelse ($months as $month => $shifts)
<section class="shift-section">
<h2>{{ $month }}</h2>
@foreach ($shifts as $shift)
@include('volunteer._shift-card', ['shift' => $shift, 'action' => 'release', 'tag' => $shift->card_tag])
@endforeach
</section>
@empty
<p class="muted">You're not on any shifts coming up.</p>
@endforelse
@endsection
