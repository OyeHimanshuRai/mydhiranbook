{{-- Shared error page. Needs no database, session or auth: every lookup is guarded. Expects $code, $title, $text. --}}
@php
    try { $signedIn = auth()->check(); } catch (\Throwable) { $signedIn = false; }
    $admin = request()->is('admin', 'admin/*');
    $contact = collect([config('business.email'), config('business.phone')])->filter()->implode(' or ');
@endphp
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#7a1a1d">
<title>{{ $title }} · MyDhiranBook</title><link rel="icon" href="/mydhiranbook-favicon.svg">
@php try { echo app(\Illuminate\Foundation\Vite::class)('resources/css/site.css'); } catch (\Throwable) {} @endphp
</head><body class="site">
<header class="site-head site-head--mini"><div class="site-head-in"><a href="/" class="brand">mydhiranbook<span>.</span></a></div></header>
<main id="main" class="site-main ck-main"><div class="ck ck-narrow">
    <p class="ck-code" aria-hidden="true">{{ $code }}</p>
    <h1>{{ $title }}</h1>
    <p class="lede">{{ $text }}</p>
    <p><a href="javascript:history.back()" class="ui-btn ui-btn--ghost">Go back</a>
    @if($signedIn)<a href="{{ url($admin ? '/admin' : '/') }}" class="ui-btn">Go to my dashboard</a>@else<a href="{{ url('/login') }}" class="ui-btn">Sign in</a>@endif</p>
    @if($contact)<p class="lede">Contact support: {{ $contact }}</p>@endif
</div></main></body></html>
