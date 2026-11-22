{{-- The signed-in app's one footer (owner 2026-11-22): a slim legal line at the end of the scroll area. Drawn once by
     <x-dhiran-layout>; pages never add their own. --}}
<footer class="dh-footer">
    <nav aria-label="Legal and help">
        <a href="/terms">Terms</a>
        <a href="/privacy">Privacy</a>
        <a href="/refund">Refunds</a>
        <a href="/contact">Contact</a>
    </nav>
    <p>&copy; {{ date('Y') }} {{ config('business.legal_name') ?: config('business.name') }}</p>
</footer>
