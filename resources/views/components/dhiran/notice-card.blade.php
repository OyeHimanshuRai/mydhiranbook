{{-- The notices card: a big card in the middle of the screen. Opens by itself once a day (first visit), and from the bell any time. --}}
@php($__v = \App\Support\NoticeView::card())
@if($__v)
<dialog id="noticesCard" class="dh-dialog nc" aria-labelledby="noticesCard-t" data-auto="{{ $__v['auto'] ? '1' : '0' }}" data-seen-url="{{ route('dhiran.notices.seen') }}">
    <div class="nc-head">
        <div class="min-w-0">
            <h3 id="noticesCard-t">{{ $__v['items'] ? 'Needs your attention' : 'All clear' }}</h3>
            <p>{{ now()->format('l, j F Y') }}</p>
        </div>
        <button type="button" class="nc-x" aria-label="Close" data-notices-close><x-icon name="x" /></button>
    </div>
    <div class="nc-body">
        @forelse($__v['items'] as $n)
            <a class="nc-row nc-{{ $n['tone'] }}" href="{{ $n['url'] }}" data-notices-close>
                <span class="nc-text"><strong>{{ $n['title'] }}</strong><span>{{ $n['hint'] }}</span></span>
                <span class="nc-go" aria-hidden="true">View <x-icon name="chevron-right" /></span>
            </a>
        @empty
            <p class="nc-empty">Nothing is due soon. New items appear here and on the bell.</p>
        @endforelse
    </div>
    <div class="nc-foot">
        @can('dhiran.settings')<a href="{{ route('dhiran.settings.notices') }}" class="btn btn-secondary btn-sm">Notification settings</a>@endcan
        <button type="button" class="btn btn-dark btn-sm" data-notices-close>Close</button>
    </div>
</dialog>
@once
<script>
    // One listener for the page's life (Turbo swaps the body, not the document). The bell opens the card; links and Close shut it.
    if (!window.__noticesBound) {
        window.__noticesBound = true;
        document.addEventListener('click', (e) => {
            const dlg = document.getElementById('noticesCard');
            if (e.target.closest('[data-notices-open]')) { dlg?.showModal(); }
            else if (e.target.closest('[data-notices-close]')) { dlg?.close(); }
            else if (e.target === dlg) { dlg.close(); } // a click on the scrim
        });
        // The card opens by itself once a day; only once it is really on screen do we tell the server (so a prefetch never uses it up).
        window.__noticesAuto = () => {
            const dlg = document.querySelector('#noticesCard[data-auto="1"]');
            if (!dlg || dlg.open) { return; }
            dlg.showModal();
            const seen = () => fetch(dlg.dataset.seenUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '', 'X-Requested-With': 'XMLHttpRequest' } });
            // The bell badge goes quiet at once and comes back if the server did not take "seen" (resources/js/ux/recovery.js).
            window.mdbOptimistic ? window.mdbOptimistic(document.querySelector('.dh-bell'), { apply: (b) => b?.classList.add('is-seen'), rollback: (b) => b?.classList.remove('is-seen'), request: seen }) : seen().catch(() => {});
        };
        document.addEventListener('turbo:load', () => window.__noticesAuto());
        document.addEventListener('turbo:before-cache', () => document.getElementById('noticesCard')?.close());
    }
    window.__noticesAuto();
</script>
@endonce
@endif
