@extends('layouts.app')

@section('title', 'Save your recovery codes')
@section('header', $header ?? 'Secure your account')

@section('content')
<p><strong>{{ $message ?? 'The app is connected.' }}</strong></p>
<h1>Save your recovery codes</h1>
<p>If you lose your phone or get a new one, each of these codes lets you log in once instead of a code from the app. Keep them somewhere safe, like a password manager or a printed copy at home.</p>
<section class="panel">
<div class="codes" id="recovery-codes">
@foreach ($codes as $code)
<div class="code">{{ $code }}</div>
@endforeach
</div>
<div class="codes">
<button type="button" class="btn" id="copy-codes" hidden>Copy codes</button>
<a class="btn" href="data:text/plain;charset=utf-8,{{ rawurlencode(implode("\n", $codes)) }}" download="{{ $filename }}">Download codes</a>
</div>
<p class="muted" id="copy-failed" role="alert" hidden>Couldn't copy. Select the codes and copy them instead.</p>
</section>
<p class="muted">You won't see these codes again on this page. If you lose them, you can make a new set from your profile, and the old ones stop working.</p>
<a class="btn btn-primary" href="{{ route('home') }}">I've saved my codes</a>
@endsection

@push('scripts')
<script src="{{ asset('js/recovery-codes.js') }}" defer></script>
@endpush
