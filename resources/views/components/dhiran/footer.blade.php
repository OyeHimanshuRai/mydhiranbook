{{-- The signed-in app's slim legal line (owner 2026-11-22): shown ONLY on the four Settings pages, at the end of their
     content. <x-dhiran-layout footer> draws it; every other signed-in page has no footer. --}}
<footer class="dh-footer">
    <nav aria-label="Legal and help">
        <a href="/terms">Terms</a>
        <a href="/privacy">Privacy</a>
        <a href="/refund">Refunds</a>
        <a href="/contact">Contact</a>
    </nav>
    <p>&copy; {{ date('Y') }} {{ config('business.legal_name') ?: config('business.name') }}</p>
</footer>
