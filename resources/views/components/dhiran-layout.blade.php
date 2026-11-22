<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dh-html">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Alpine-rendered pages are not snapshot-safe: restoring a Turbo snapshot re-ran x-for clones without their scope. --}}
    <meta name="turbo-cache-control" content="no-cache">
    <title>{{ $title ?? 'Dhiran' }}</title>
    @include('partials.favicon')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/css/dhiran.css', 'resources/js/app.js'])

    {{-- Dhiran shell — a dedicated, Dhiran-only navigation surface. Only
         pledge-loan navigation appears here. --}}
</head>
<body class="dh-app">
    <a href="#dh-main" class="dh-skip">Skip to content</a>
    {{-- Phones (< 768px): the menu is a slide-in drawer. While it is open the page behind is inert (a real focus trap),
         Esc and the scrim close it, and focus goes back to the button that opened it. Tablets get an icon rail and
         desktops the full sidebar, both always visible. --}}
    <div class="dh-shell"
         x-data="{
             show: false, drawer: false, opener: null,
             init() {
                 const mq = window.matchMedia('(max-width: 767.98px)');
                 this.drawer = mq.matches;
                 mq.addEventListener('change', e => { this.drawer = e.matches; if (!e.matches) this.show = false; });
             },
             openMenu() { this.opener = document.activeElement; this.show = true; this.$nextTick(() => this.$refs.sidebar.querySelector('a, button')?.focus()); },
             closeMenu() { if (!this.show) return; this.show = false; this.$nextTick(() => this.opener?.focus?.()); },
             trap(e) {
                 if (!this.show || !this.drawer) return;
                 const f = [...this.$refs.sidebar.querySelectorAll('a[href], button')];
                 const [first, last] = [f[0], f[f.length - 1]];
                 if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                 else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
             }
         }"
         :class="{ 'is-open': show }"
         x-effect="$refs.main.inert = show && drawer; $refs.sidebar.inert = drawer && !show; document.querySelectorAll('.dh-header-toggle').forEach(b => b.setAttribute('aria-expanded', show))"
         @keydown.escape.window="closeMenu()"
         @keydown.tab.window="trap($event)"
         @turbo:before-cache.window="show = false"
         @dhiran-menu.window="openMenu()">

        {{-- Scrim: tap to close the drawer. --}}
        <div class="dh-scrim" @click="closeMenu()" aria-hidden="true"></div>

        <aside class="dh-sidebar" id="dh-sidebar" x-ref="sidebar" :role="drawer ? 'dialog' : null" :aria-modal="drawer ? 'true' : null" :aria-label="drawer ? 'Main menu' : null">
            <div class="dh-sidebar-head">
                <a href="{{ route('dhiran.loans') }}" class="dh-brand" aria-label="Dhiran, Loans" @click="show = false">
                    <span class="dh-wordmark" aria-hidden="true">dhiran<span>.</span></span>
                </a>
                <button type="button" class="dh-sidebar-close"
                        @click="closeMenu()"
                        aria-label="Close menu">
                    <x-icon name="x" />
                </button>
            </div>

            {{-- Dhiran-only navigation. Pledge-loan links only. --}}
            <nav class="dh-nav" aria-label="Main">
                @if((bool) auth()->user()?->shop_id)
                    <a href="{{ route('dhiran.loans') }}" class="dh-nav-link {{ request()->routeIs('dhiran.loans') || request()->routeIs('dhiran.show') ? 'active' : '' }}" @if(request()->routeIs('dhiran.loans') || request()->routeIs('dhiran.show')) aria-current="page" @endif @click="show = false">
                        <x-icon name="credit-card" />
                        <span class="dh-nav-text">Loans</span>
                    </a>
                    <a href="{{ route('dhiran.borrowers.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.borrowers.*') ? 'active' : '' }}" @if(request()->routeIs('dhiran.borrowers.*')) aria-current="page" @endif @click="show = false">
                        <x-icon name="users" />
                        <span class="dh-nav-text">Customers</span>
                    </a>
                    <a href="{{ route('dhiran.create') }}" class="dh-nav-link {{ request()->routeIs('dhiran.create') ? 'active' : '' }}" @if(request()->routeIs('dhiran.create')) aria-current="page" @endif @click="show = false">
                        <x-icon name="plus" />
                        <span class="dh-nav-text">New Loan</span>
                    </a>
                    @can('dhiran.create')
                    <a href="{{ route('dhiran.old-loans.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.old-loans.*') ? 'active' : '' }}" @if(request()->routeIs('dhiran.old-loans.*')) aria-current="page" @endif @click="show = false">
                        <x-icon name="book-open" />
                        <span class="dh-nav-text">Old Loans</span>
                    </a>
                    @endcan
                    <a href="{{ route('dhiran.reports.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.reports.*') ? 'active' : '' }}" @if(request()->routeIs('dhiran.reports.*')) aria-current="page" @endif @click="show = false">
                        <x-icon name="chart-no-axes-column" />
                        <span class="dh-nav-text">Reports</span>
                    </a>
                    <a href="{{ route('dhiran.settings') }}" class="dh-nav-link {{ request()->routeIs('dhiran.settings*') ? 'active' : '' }}" @if(request()->routeIs('dhiran.settings*')) aria-current="page" @endif @click="show = false">
                        <x-icon name="settings" />
                        <span class="dh-nav-text">Settings</span>
                    </a>
                @endif
            </nav>

            <div class="dh-foot">
                <div class="dh-foot-user">{{ auth()->user()->name ?? \App\Support\Mobile::forDisplay(auth()->user()?->mobile_number) }}</div>
                <div class="dh-foot-shop">{{ auth()->user()->shop?->name ?? 'Dhiran account' }}</div>
                <form method="POST" action="{{ route('logout') }}" data-turbo-frame="_top" data-confirm-message="Sign out of Dhiran?">
                    @csrf
                    <button type="submit" class="dh-logout"><x-icon name="log-out" /><span>Sign out</span></button>
                </form>
            </div>
        </aside>

        <main class="dh-main" id="dh-main" x-ref="main" tabindex="-1">
            @php
                // Rendering the page first lets <x-dhiran.page-header> push itself into the header band below.
                $slotMarkup = (string) $slot;
                $pageHeader = trim($__env->yieldPushContent('dh-page-header'));
                $hasPageHeader = $pageHeader !== '';
            @endphp

            {{-- Header band, then the scroll area: on desktop only .dh-scroll scrolls, so the page scrollbar starts
                 under the header instead of running beside it. --}}
            @if($hasPageHeader)
                {!! $pageHeader !!}
            @else
                <div class="content-header dh-page-header dh-page-header-fallback">
                    <div class="dh-page-header-content">
                        <button type="button" class="dh-header-toggle"
                                onclick="window.dispatchEvent(new CustomEvent('dhiran-menu'))"
                                aria-label="Open menu" aria-controls="dh-sidebar" aria-expanded="false">
                            <x-icon name="menu" />
                        </button>
                        <div class="min-w-0">
                            <h1 class="page-title">{{ $title ?? 'Dhiran' }}</h1>
                        </div>
                    </div>
                </div>
            @endif

            <div class="dh-scroll">
                {{-- Flash messages are TOASTS (resources/js/toast.js reads these tags): they float over the page, never push it. --}}
                @if(session('success'))<meta name="flash-success" content="{{ session('success') }}">@endif
                @if(session('warning'))<meta name="flash-warning" content="{{ session('warning') }}">@endif
                @if(session('error'))<meta name="flash-error" content="{{ session('error') }}">
                @elseif(isset($errors) && $errors->any())<meta name="flash-error" content="{{ $errors->first() }}">@endif

                <div class="dh-content">{!! $slotMarkup !!}</div>
                <x-dhiran.footer />
            </div>
        </main>
    </div>
    <div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>
    @stack('scripts')
</body>
</html>
