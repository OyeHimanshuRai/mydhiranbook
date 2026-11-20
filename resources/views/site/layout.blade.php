<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title') · Dhiran</title><meta name="description" content="@yield('description', 'Dhiran is pledge-loan management software for Indian jewellers and pawn-lending shops.')"><link rel="icon" href="/dhiran-favicon.svg"><link rel="stylesheet" href="/app.css">@vite('resources/css/site.css')</head><body class="site">
<a class="skip" href="#main">Skip to content</a>
<header class="site-head"><a href="/" class="brand">dhiran<span>.</span></a><nav aria-label="Main"><a href="/pricing">Pricing</a><a href="/contact">Contact</a><a href="/login">Sign in</a><a href="/register" class="button gold small">Get started</a></nav></header>
<main id="main">@yield('content')</main>
@include('site._footer')
</body></html>
