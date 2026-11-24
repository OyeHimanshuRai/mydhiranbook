@php
    $u = auth('platform_admin')->user();
    $nav = [
        ['/admin', 'Dashboard', null, 'admin'],
        ['/admin/shops', 'Shops', 'can_manage_shops', 'admin/shops*'],
        ['/admin/plans', 'Plans', 'can_manage_plans', 'admin/plans'],
        ['/admin/unresolved-payments', 'Unresolved payments', 'can_manage_subscriptions', 'admin/unresolved-payments'],
        ['/admin/accounts', 'Admin accounts', 'can_manage_admins', 'admin/accounts'],
        ['/admin/audit', 'Audit log', 'can_view_audit_logs', 'admin/audit'],
    ];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#7a1a1d">
<title>@yield('title','MyDhiranBook platform') · MyDhiranBook admin</title>
<link rel="icon" href="/mydhiranbook-favicon.svg">
<script>document.documentElement.classList.add('js')</script>
@vite('resources/css/admin.css')
</head>
<body class="adm">
<a href="#main" class="adm-skip">Skip to content</a>
<aside class="adm-side" id="adm-side" aria-label="Platform admin">
    <a href="/admin" class="adm-brand" aria-label="dhiran. platform admin home">dhiran<span>.</span></a>
    <nav class="adm-nav" aria-label="Main">
        @foreach($nav as [$href, $label, $perm, $match])
            @if(!$perm || $u->hasPermission($perm))<a href="{{ $href }}" @if(request()->is($match)) aria-current="page" @endif>{{ $label }}</a>@endif
        @endforeach
    </nav>
    <p class="adm-foot">&copy; {{ date('Y') }} MyDhiranBook · MFA protected</p>
</aside>
<div class="adm-scrim" id="adm-scrim" aria-hidden="true"></div>
<div class="adm-shell">
    <header class="adm-top">
        <button type="button" class="adm-burger" id="adm-burger" aria-expanded="false" aria-controls="adm-side">Menu</button>
        <a href="/admin" class="adm-brand" aria-label="dhiran. platform admin home">dhiran<span>.</span></a>
        <div class="adm-account"><span title="{{ $u->name }}">{{ $u->name }}</span><form action="/admin/logout" method="post">@csrf<button class="adm-btn adm-btn--ghost">Sign out</button></form></div>
    </header>
    <main class="adm-content" id="main" tabindex="-1">
        @include('flash')
        @if(session('recovery_codes'))
            <div class="notice"><h2>Recovery codes</h2><p>Save these somewhere private. Each code works once. They are shown only now.</p>@foreach(session('recovery_codes') as $code)<code class="adm-code">{{ $code }}</code>@endforeach</div>
        @endif
        @yield('content')
    </main>
</div>
<script>
(function(){var d=document,b=d.getElementById('adm-burger'),s=d.getElementById('adm-side'),c=d.getElementById('adm-scrim');
function set(o){b.setAttribute('aria-expanded',o);s.classList.toggle('is-open',o);c.classList.toggle('is-open',o);if(o){var a=s.querySelector('a[aria-current],.adm-nav a');a&&a.focus()}}
b.addEventListener('click',function(){set(b.getAttribute('aria-expanded')!=='true')});
c.addEventListener('click',function(){set(false)});
d.addEventListener('keydown',function(e){if(e.key==='Escape'&&b.getAttribute('aria-expanded')==='true'){set(false);b.focus()}});
var x=d.querySelector('[aria-invalid=true]');if(x)x.focus()})();
</script>
</body>
</html>
