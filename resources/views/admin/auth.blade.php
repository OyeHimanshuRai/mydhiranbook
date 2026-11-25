@php $stray = collect($errors->messages())->except(['email', 'password', 'code'])->flatten(); @endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#7a1a1d">
<title>@yield('title') · MyDhiranBook platform</title>
<link rel="icon" href="/mydhiranbook-favicon.svg">
<script>document.documentElement.classList.add('js')</script>
@vite('resources/css/admin.css')
</head>
<body class="adm adm-auth">
<a href="#main" class="adm-skip">Skip to content</a>
<a href="/admin/login" class="adm-auth-brand" aria-label="dhiran. platform admin">mydhiranbook<span>.</span></a>
<main class="adm-auth-card" id="main">
    @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
    @if($stray->isNotEmpty())<div class="notice error" role="alert"><ul>@foreach($stray as $m)<li>{{ $m }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<p class="adm-auth-foot">&copy; {{ date('Y') }} MyDhiranBook · Platform admin</p>
<script>var x=document.querySelector('[aria-invalid=true]');if(x)x.focus();</script>
</body>
</html>
