@props(['title' => 'Subscription'])
@if(auth()->user()?->shop_id)
<x-dhiran-layout :title="$title">{{ $slot }}</x-dhiran-layout>
@else
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $title }} · Dhiran</title><link rel="stylesheet" href="/app.css"></head><body><main class="workspace" style="max-width:960px">
<div class="brand" style="color:inherit;margin-bottom:24px">dhiran<span>.</span></div>
@include('flash')@if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
{{ $slot }}
<form method="post" action="{{ route('logout') }}" style="margin-top:32px">@csrf<button class="secondary small">Sign out</button></form>
</main></body></html>
@endif
