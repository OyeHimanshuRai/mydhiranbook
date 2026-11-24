@extends('admin.auth') @section('title','Verify admin access') @section('content')
<h1>{{ $admin->two_factor_enabled?'Verify your identity':'Set up your authenticator' }}</h1><p class="lead">{{ $admin->two_factor_enabled?'Enter a fresh six-digit authenticator code, or an unused recovery code.':'Add this MyDhiranBook account to your authenticator, then enter its six-digit code.' }}</p>
@if(!$admin->two_factor_enabled)<p class="adm-legend" style="margin:var(--sp-4) 0 0;font-weight:650;font-size:var(--fs-sm)">Account</p><p class="adm-wrap" style="margin:0">{{ $admin->email }}</p><p class="adm-legend" style="margin:var(--sp-3) 0 0;font-weight:650;font-size:var(--fs-sm)">Setup key · issuer: MyDhiranBook</p><code class="adm-code">{{ $admin->two_factor_secret }}</code><p class="adm-hint" style="margin:0">Time based · 6 digits · 30 seconds. Keep this key private.</p>@endif
<form method="post" action="/admin/mfa">@csrf
@include('admin._field',['name'=>'code','label'=>'Authenticator or recovery code','attrs'=>['autocomplete'=>'one-time-code','autofocus'=>'autofocus','required'=>'required','maxlength'=>'30','autocapitalize'=>'off','spellcheck'=>'false']])
<button class="adm-btn adm-btn--block">Verify &amp; open dashboard</button></form>
<form action="/admin/logout" method="post" class="adm-auth-links">@csrf<button class="adm-link-btn">Cancel sign in</button></form>
@endsection
