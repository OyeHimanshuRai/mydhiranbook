@extends('auth.base') @section('title','Reset password') @section('content')
<p class="eyebrow">ACCOUNT RECOVERY</p><h1>Reset your password.</h1><p class="muted">Enter your account email and we will send you a reset link.</p><form method="post" action="{{ route('password.email') }}">@csrf<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"></div><button class="gold">Send reset link</button></form><p class="footnote"><a href="/login">Back to sign in</a></p>
@endsection
