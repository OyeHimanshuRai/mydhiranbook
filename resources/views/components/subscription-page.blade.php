@props(['title' => 'Subscription', 'footer' => false])
@if(auth()->user()?->shop_id)
<x-dhiran-layout :title="$title" :footer="$footer"><div class="ck-shell">{{ $slot }}</div>@include('site._js')</x-dhiran-layout>
@else
<!doctype html><html lang="en"><head>@include('site._head', ['title' => $title])</head><body class="site">
<a class="skip" href="#main">Skip to content</a>
<header class="site-head site-head--mini"><div class="site-head-in"><a href="/" class="brand">dhiran<span>.</span></a><form method="post" action="{{ route('logout') }}">@csrf<button class="ui-btn ui-btn--onnavy">Sign out</button></form></div></header>
<main id="main" class="site-main ck-main">@if(session('success'))<div class="ui-notice ui-notice--success" role="status">{{ session('success') }}</div>@endif @if($errors->any())<div class="ui-notice ui-notice--error" role="alert"><ul class="ck-list">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif @if(session('error'))<div class="ui-notice ui-notice--error" role="alert">{{ session('error') }}</div>@endif
{{ $slot }}</main>
@include('site._footer', ['siteCss' => true])@include('site._js')</body></html>
@endif
