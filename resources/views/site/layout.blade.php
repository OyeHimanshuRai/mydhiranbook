<!doctype html><html lang="en"><head>@include('site._head', ['title' => $__env->yieldContent('title'), 'description' => $__env->yieldContent('description')])</head><body class="site @yield('bodyclass')">
<a class="skip" href="#main">Skip to content</a>
@hasSection('shell')@yield('shell')@else
<header class="site-head"><div class="site-head-in"><a href="/" class="brand">dhiran<span>.</span></a><button type="button" class="site-burger" data-menu aria-expanded="false" aria-controls="site-nav">Menu</button><nav id="site-nav" class="site-nav" aria-label="Main"><a href="/pricing" @if(request()->is('pricing')) aria-current="page" @endif>Pricing</a><a href="/contact" @if(request()->is('contact')) aria-current="page" @endif>Contact</a><a href="/login">Sign in</a><a href="/register" class="ui-btn ui-btn--sm">Get started</a></nav></div></header>
<main id="main" class="site-main">@yield('content')</main>
@include('site._footer', ['siteCss' => true])
@endif
@include('site._js')@stack('scripts')
</body></html>
