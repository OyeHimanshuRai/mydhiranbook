<x-dhiran-layout title="Reports">
    @vite('resources/css/site.css')
    <div class="ui-modal">
        <div class="ui-modal-card" role="dialog" aria-modal="true" aria-labelledby="report-lock-title">
            <h2 id="report-lock-title">Enter your password to open reports</h2>
            <p>Reports stay open for 1 hour after you confirm.</p>
            <form method="post" action="{{ route('password.confirm.store') }}" data-turbo="false" data-once>
                @csrf
                <x-site.field name="password" label="Password" password required autofocus autocomplete="current-password" />
                <div class="ui-modal-actions">
                    <a href="{{ route('dhiran.loans') }}" class="ui-btn ui-btn--ghost">Cancel</a>
                    <button type="submit" class="ui-btn ui-btn--gold" data-busy="Checking…">Open reports</button>
                </div>
            </form>
        </div>
    </div>
    @include('site._js')
</x-dhiran-layout>
