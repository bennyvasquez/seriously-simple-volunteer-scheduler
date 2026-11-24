{{-- "It's yours" (wireframe 45), for someone on the shift. $told names who was emailed besides them; $regularLine is there when they took it every week or every other week. --}}
@extends('layouts.app')

@php
    $heading = 'It\'s yours. Thanks, '.$user->firstName().'!';
@endphp

@section('title', $heading)

@section('content')
<div role="status" class="result">
<h1>{{ $heading }}</h1>
<p class="when">{{ $card['date'] }}</p>
<p>{{ $card['what'] }}</p>
@if ($regularLine)<p>{{ $regularLine }}</p>@endif
@if ($shadows !== [])
<p class="muted">{{ App\Http\Controllers\Volunteer\ReleaseController::names($shadows) }} {{ count($shadows) === 1 ? 'is' : 'are' }} shadowing this shift, so they'll be learning from you.</p>
@endif
<p class="muted">We've emailed you the details{{ $told === [] ? '.' : App\Http\Controllers\Volunteer\ReleaseController::endSentence(', and told '.App\Http\Controllers\Volunteer\ReleaseController::names($told)) }}</p>
</div>
<p class="muted">Can't make it after all? It's under "Your next shifts" on your home page.</p>
<a href="{{ route('home') }}" class="btn btn-primary">Back to home</a>
@endsection
