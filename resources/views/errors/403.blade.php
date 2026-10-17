@extends('layouts.app')

@section('title', 'You can\'t open this page')

@section('content')
<h1>You can't open this page</h1>
<p>This page is for someone with a different role. If you think you should be able to open it, ask a coordinator.</p>
<a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
@endsection
