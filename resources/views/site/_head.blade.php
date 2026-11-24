{{-- $title and $description are plain text: escaped here, at the sink. --}}
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#7a1a1d"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title }} · MyDhiranBook</title><meta name="description" content="{{ ($description ?? '') ?: 'MyDhiranBook is pledge-loan management software for Indian jewellers and pawn-lending shops.' }}"><link rel="icon" href="/dhiran-favicon.svg">
<script>document.documentElement.classList.add('js')</script>@vite('resources/css/site.css')
