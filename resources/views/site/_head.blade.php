{{-- $title and $description arrive already escaped. --}}
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0f1b2d"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>{!! $title !!} · Dhiran</title><meta name="description" content="{!! ($description ?? '') ?: 'Dhiran is pledge-loan management software for Indian jewellers and pawn-lending shops.' !!}"><link rel="icon" href="/dhiran-favicon.svg">
<script>document.documentElement.classList.add('js')</script>@vite('resources/css/site.css')
