@extends('auth.base') @section('title','Shop sign in') @section('content')
<h1>Open your book.</h1><p class="lede">Sign in to your shop account.</p>
<form method="post" action="/login" data-once>@csrf
<x-site.field name="email" label="Email or mobile number" type="text" :value="old('email')" required autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" />
<x-site.field name="password" label="Password" password required autocomplete="current-password"><x-slot:aside><a href="{{ route('password.request') }}" class="ui-link">Forgot password?</a></x-slot:aside></x-site.field>
<p class="ui-hint" data-caps hidden aria-live="polite">Caps Lock is on.</p>
<button class="ui-btn ui-btn--gold ui-btn--block ui-submit" data-busy="Signing in…">Sign in</button></form>
<div class="auth-alt"><span>New to Dhiran?</span><a href="/register" class="ui-btn ui-btn--ghost ui-btn--block">Create your account</a></div>
@endsection
