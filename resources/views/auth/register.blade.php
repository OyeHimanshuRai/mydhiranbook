@extends('auth.base') @section('title','Create an account') @section('content')
<h1>Your own book.</h1><p class="lede">Create your Dhiran owner account. You choose a plan and set up your business next.</p>
<form method="post" action="/register" data-once>@csrf
<fieldset class="ui-group"><legend>About you</legend>
<x-site.field name="name" label="Your name" req :value="old('name')" required autocomplete="name" />
<x-site.field name="email" label="Email address" type="email" req :value="old('email')" required autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" />
<x-site.field name="mobile" label="Mobile number" type="tel" req :value="old('mobile')" required autocomplete="tel" inputmode="tel" hint="10-digit Indian mobile number." /></fieldset>
<fieldset class="ui-group"><legend>Secure your account</legend>
<x-site.field name="password" label="Password" password req required minlength="12" autocomplete="new-password" hint="At least 12 characters." />
<x-site.field name="password_confirmation" label="Confirm password" password required autocomplete="new-password" /></fieldset>
<label class="ui-check{{ $errors->has('terms') ? ' has-err' : '' }}"><input type="checkbox" name="terms" value="1" required @checked(old('terms')) @if($errors->has('terms')) aria-invalid="true" aria-describedby="terms-err" @endif><span>I accept the <a href="/terms" target="_blank" rel="noopener">Terms of Service</a> and <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a><span class="sr-only"> (open in new tabs)</span>.</span></label>
@error('terms')<p id="terms-err" class="ui-err" role="alert">{{ $message }}</p>@enderror
<button class="ui-btn ui-btn--block ui-submit" data-busy="Creating account…">Create account</button></form>
<div class="auth-alt"><span>Already have an account?</span><a href="/login" class="ui-btn ui-btn--ghost ui-btn--block">Sign in</a></div>
@endsection
