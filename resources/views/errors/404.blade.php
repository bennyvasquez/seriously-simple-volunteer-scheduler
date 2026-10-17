@extends('layouts.app')

@section('title', 'We can\'t find that page')

@section('content')
<h1>We can't find that page</h1>
<p>The address may have a typo, or the page may have moved.</p>
<a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
@endsection
