<x-dhiran-layout title="Refer a jeweller">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Refer a jeweller</h1>
            <p class="text-sm text-gray-500 mt-1">Know another shop that would use MyDhiranBook? Share your link.</p>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dh-ref">
        <section class="dh-ref-card" aria-labelledby="dh-ref-link-h">
            <h2 class="dh-ref-h" id="dh-ref-link-h">Your link</h2>
            <div class="dh-ref-row">
                <input type="text" id="dh-ref-link" class="dh-ref-link" value="{{ $link }}" readonly aria-label="Your referral link" onfocus="this.select()">
                <button type="button" class="btn btn-secondary" id="dh-ref-copy" hidden><x-icon name="copy" size="16" /> <span>Copy</span></button>
            </div>
            <p class="dh-ref-code">Your referral code: <strong class="font-mono">{{ $code }}</strong></p>
            <p class="dh-ref-note" id="dh-ref-note" role="status" aria-live="polite"></p>
            <ol class="dh-ref-steps">
                <li>Share your link with a jeweller.</li>
                <li>They register and send their shop details.</li>
                <li>We check and approve it. It then counts here.</li>
            </ol>
        </section>

        <section class="dh-ref-card" aria-labelledby="dh-ref-list-h">
            <h2 class="dh-ref-h" id="dh-ref-list-h">Shops you referred <span class="dh-ref-count">{{ $approved }} approved</span></h2>
            @if($referred->isEmpty())
                <p class="dh-ref-empty">No shop has come through your link yet.</p>
            @else
                <div class="dh-table-card">
                    <div class="dh-table-wrap" tabindex="0" role="region" aria-label="Shops you referred">
                        <table class="dh-table dh-stack">
                            <thead><tr><th scope="col">Shop</th><th scope="col">Date</th><th scope="col">State</th></tr></thead>
                            <tbody>
                                @foreach($referred as $r)
                                    <tr>
                                        <td data-label="Shop" data-cell="title" class="font-medium">{{ $r->name }}</td>
                                        <td data-label="Date" class="whitespace-nowrap">{{ $r->created_at?->format('d M Y') }}</td>
                                        <td data-label="State">
                                            @if(in_array($r->status, ['active', 'suspended'], true))<span class="dh-status dh-status--success">Approved</span>
                                            @elseif($r->status === 'rejected')<span class="dh-status dh-status--muted">Not approved</span>
                                            @else<span class="dh-status dh-status--warning">Waiting</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    </div>

    {{-- Copy needs the clipboard API; without it the button stays hidden and the link above is plain selectable text. --}}
    @push('scripts')
    <script>
        (function () {
            var b = document.getElementById('dh-ref-copy'), i = document.getElementById('dh-ref-link'), n = document.getElementById('dh-ref-note');
            if (!b || !i) { return; }
            try { if (!navigator.clipboard || !navigator.clipboard.writeText) { return; } } catch (e) { return; }
            b.hidden = false;
            b.addEventListener('click', function () {
                try {
                    navigator.clipboard.writeText(i.value).then(function () { n.textContent = 'Link copied.'; }, function () { n.textContent = 'Could not copy. Select the link and copy it.'; });
                } catch (e) { n.textContent = 'Could not copy. Select the link and copy it.'; }
            });
        })();
    </script>
    @endpush
</x-dhiran-layout>
