@extends('layouts.app')

@section('title', 'This page was open too long')

@section('content')
<h1>This page was open too long</h1>
<p>To keep your account safe, a page stops accepting what you send after a while, so we didn't save anything. Go back and try again.</p>
<p class="muted">If that doesn't work, log in again and start over.</p>
<a class="btn btn-primary" href="{{ route('login') }}">Go to log in</a>
@endsection
