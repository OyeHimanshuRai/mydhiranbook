@extends('site.layout')
@section('bodyclass', 'auth')
@section('shell')
<aside class="auth-brand"><a href="/" class="brand" aria-label="Dhiran home">dhiran<span>.</span></a><div><div class="auth-rule"></div><h2>A clear book.<br>A confident day.</h2><p>Borrowers, pledges and repayments. Every detail, in its place.</p></div><p class="ui-eyebrow">Your independent pledge book</p></aside>
<div class="auth-side"><main id="main" class="auth-main"><div class="auth-card">@if(session('success'))<div class="ui-notice ui-notice--success" role="status">{{ session('success') }}</div>@endif @yield('content')</div></main>@include('site._footer', ['siteCss' => true])</div>
@endsection
