{{-- Receipt settings: one receipt at a time (Pledge | Payment), each field a switch row inside a titled group. A switched-on
     field is posted as show[] (the controller stores everything else as hidden). No JS: both receipts stack, all controls still post. --}}
@php
    use App\Models\Dhiran\DhiranSettings;
    $receipts = DhiranSettings::RECEIPT_FIELDS;
    $lead = ['pledge' => 'Given to the borrower when a loan is made.', 'payment' => 'Given every time a payment is received.'];
    $total = fn ($def) => collect($def['groups'])->sum(fn ($f) => count($f));
    $on = fn ($receipt, $fields) => collect(array_keys($fields))->filter(fn ($k) => in_array("$receipt.$k", $printed, true))->count();
@endphp
<x-dhiran-layout title="Receipt settings">
    <x-dhiran.page-header>
        <x-dhiran.settings-nav active="receipt" as="title" />
    </x-dhiran.page-header>
<div class="content-inner dhiran-settings-root rs">
<x-dhiran.settings-nav active="receipt" switcher />
<form id="rs-form" class="rs-form" method="POST" action="{{ route('dhiran.settings.receipt') }}" data-turbo-frame="_top"
      x-data="rsReceiptSettings({{ \Illuminate\Support\Js::from(['dirty' => session()->hasOldInput(), 'saved' => session('success')]) }})"
      @input="touch($event)" @change="touch($event)" @submit="saving = true" @beforeunload.window="if (dirty && !saving) $event.preventDefault()">
    @csrf
    @method('PATCH')

    <p class="rs-lead">Choose what is printed on customer receipts. A field you switch off is left out and the receipt closes up around it.</p>

    <div class="rs-toolbar">
        <div class="rs-seg" role="tablist" aria-label="Which receipt to change" @keydown.arrow-right.prevent="move(1)" @keydown.arrow-left.prevent="move(-1)">
            @foreach($receipts as $receipt => $def)
                <button type="button" role="tab" id="rs-tab-{{ $receipt }}" aria-controls="rs-panel-{{ $receipt }}"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? 0 : -1 }}"
                        :aria-selected="tab === '{{ $receipt }}'" :tabindex="tab === '{{ $receipt }}' ? 0 : -1" @click="setTab('{{ $receipt }}')">{{ $def['label'] }}</button>
            @endforeach
        </div>
        @if($sampleLoan)
            <a class="rs-sample" href="{{ route('dhiran.receipt', $sampleLoan) }}" target="_blank" rel="noopener" x-show="tab === 'pledge'"
               title="Opens the latest loan's receipt with the saved settings">See a sample <x-icon name="external-link" size="16" /><span class="rs-sr"> (opens in a new tab, shows saved settings)</span></a>
        @endif
    </div>

    @foreach($receipts as $receipt => $def)
        <section class="rs-panel" id="rs-panel-{{ $receipt }}" role="tabpanel" aria-labelledby="rs-tab-{{ $receipt }}" data-rs-panel="{{ $receipt }}"
                 @unless($loop->first) hidden @endunless :hidden="tab !== '{{ $receipt }}'">
            <div class="rs-panel-head">
                <h2 class="rs-panel-title">{{ $def['label'] }}</h2>
                <p class="rs-panel-lead">{{ $lead[$receipt] }} <span class="rs-panel-count" x-text="panelText('{{ $receipt }}')">{{ collect($printed)->filter(fn ($k) => str_starts_with($k, "$receipt."))->count() }} of {{ $total($def) }} fields printed</span></p>
            </div>

            <div class="rs-locked">
                <x-dhiran.switch id="rs-{{ $receipt }}-loan_number" label="Loan number" hint="Always printed. It is how a loan is found." :checked="true" locked />
            </div>

            <div class="rs-groups">
                @foreach($def['groups'] as $group => $fields)
                    @php($gid = "rs-$receipt-g$loop->index")
                    <section class="rs-group" role="group" aria-labelledby="{{ $gid }}-t" data-rs-group="{{ $gid }}">
                        <header class="rs-group-head">
                            <div class="rs-group-id">
                                <h3 class="rs-group-title" id="{{ $gid }}-t">{{ $group }}</h3>
                                <p class="rs-count" x-text="countText('{{ $gid }}')">{{ $on($receipt, $fields) }} of {{ count($fields) }} shown</p>
                            </div>
                            @if(count($fields) > 1)
                                <button type="button" class="rs-master" hidden :hidden="false" @click="master('{{ $gid }}')"><span x-text="allOn('{{ $gid }}') ? 'Hide all' : 'Show all'">{{ $on($receipt, $fields) === count($fields) ? 'Hide all' : 'Show all' }}</span><span class="rs-sr"> in {{ $group }}</span></button>
                            @endif
                        </header>
                        <div class="rs-rows">
                            @foreach($fields as $key => $label)
                                <x-dhiran.switch id="rs-{{ $receipt }}-{{ $key }}" name="show[]" value="{{ $receipt }}.{{ $key }}" :label="$label"
                                                 :hint="$hints[$receipt.'.'.$key] ?? null" :checked="in_array($receipt.'.'.$key, $printed, true)" />
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="rs-text" aria-labelledby="rs-text-t">
        <h2 class="rs-panel-title rs-text-title" id="rs-text-t">Printed text</h2>
        <p class="rs-panel-lead">Words you write once. They are printed exactly as typed.</p>
        @foreach([
            ['terms', 'receipt_terms_text', 'Terms on the pledge receipt', 'Printed under the items when "Terms and conditions" is on, for example your rules on interest and auction.', 5],
            ['closure', 'closure_certificate_text', 'Text on the closure certificate', 'Printed on the certificate given when a loan is closed.', 3],
        ] as [$tid, $name, $label, $help, $rows])
            <div class="rs-field">
                <label class="rs-field-label" for="rs-{{ $tid }}">{{ $label }}</label>
                <textarea id="rs-{{ $tid }}" name="{{ $name }}" rows="{{ $rows }}" maxlength="5000" class="dset-input dset-textarea rs-textarea" data-rs-len="{{ $tid }}"
                          aria-describedby="rs-{{ $tid }}-h @error($name) rs-{{ $tid }}-e @enderror" @error($name) aria-invalid="true" @enderror>{{ old($name, $settings->{$name}) }}</textarea>
                <p class="rs-field-foot"><span class="rs-help" id="rs-{{ $tid }}-h">{{ $help }}</span><span class="rs-len" aria-hidden="true"><span x-text="(len.{{ $tid }} ?? 0).toLocaleString('en-IN')">{{ number_format(mb_strlen((string) old($name, $settings->{$name})), 0) }}</span> / 5,000</span></p>
                @error($name)<p class="rs-error" id="rs-{{ $tid }}-e">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </section>

    <div class="rs-bar">
        <p class="rs-status" role="status" :class="{ 'is-dirty': dirty }" x-text="message">No changes yet</p>
        <div class="rs-bar-actions">
            <button type="button" class="btn btn-secondary rs-discard" hidden :hidden="!dirty" @click="discard()">Discard changes</button>
            <button type="submit" class="btn btn-primary rs-save"><x-icon name="save" size="16" /> Save changes</button>
        </div>
    </div>
</form>
</div>
<noscript><style>.rs-seg, .rs-sample, .rs-master { display: none !important; } .rs-panel[hidden] { display: block !important; } .rs-panel-title { position: static !important; width: auto !important; height: auto !important; clip-path: none !important; }</style></noscript>

@push('scripts')
<script>
    // Alpine state for the receipt form (global function, so it also works after a Turbo visit).
    window.rsReceiptSettings = (opts) => ({
        tab: 'pledge', dirty: !!opts.dirty, saving: false, announce: '', counts: {}, panels: {}, len: {}, base: null,
        get message() { return this.dirty ? 'Unsaved changes' : (this.announce || 'No changes yet'); },
        snapshot() { const d = new FormData(this.$root); d.delete('_token'); return new URLSearchParams(d).toString(); },
        init() {
            const names = ['pledge', 'payment'];
            let want = location.hash.slice(1);
            if (!names.includes(want)) { try { want = sessionStorage.getItem('rs-tab'); } catch (e) { want = null; } }
            this.tab = names.includes(want) ? want : 'pledge';
            this.$root.classList.add('rs-tabbed');
            this.recount();
            this.base = opts.dirty ? null : this.snapshot();
            if (opts.saved) setTimeout(() => { this.announce = opts.saved; }, 300); // set after load so the status region is announced
        },
        setTab(t) {
            this.tab = t;
            try { sessionStorage.setItem('rs-tab', t); history.replaceState(history.state, '', '#' + t); } catch (e) {}
        },
        move(step) { const n = ['pledge', 'payment']; const t = n[(n.indexOf(this.tab) + step + n.length) % n.length]; this.setTab(t); this.$nextTick(() => document.getElementById('rs-tab-' + t).focus()); },
        recount() {
            const counts = {}, panels = {}, len = {};
            this.$root.querySelectorAll('[data-rs-group]').forEach((g) => {
                const boxes = [...g.querySelectorAll('input[name="show[]"]')];
                counts[g.dataset.rsGroup] = { on: boxes.filter((b) => b.checked).length, total: boxes.length };
            });
            this.$root.querySelectorAll('[data-rs-panel]').forEach((p) => {
                const boxes = [...p.querySelectorAll('input[name="show[]"]')];
                panels[p.dataset.rsPanel] = { on: boxes.filter((b) => b.checked).length, total: boxes.length };
            });
            this.$root.querySelectorAll('[data-rs-len]').forEach((t) => { len[t.dataset.rsLen] = t.value.length; });
            this.counts = counts; this.panels = panels; this.len = len;
        },
        countText(id) { const c = this.counts[id]; return c ? c.on + ' of ' + c.total + ' shown' : ''; },
        panelText(id) { const c = this.panels[id]; return c ? c.on + ' of ' + c.total + ' fields printed' : ''; },
        allOn(id) { const c = this.counts[id]; return !!c && c.on === c.total; },
        master(id) {
            const turnOn = !this.allOn(id);
            this.$root.querySelectorAll('[data-rs-group="' + id + '"] input[name="show[]"]').forEach((b) => { b.checked = turnOn; });
            this.touch();
        },
        touch() { this.recount(); this.dirty = this.base === null ? true : this.snapshot() !== this.base; },
        discard() { this.$root.reset(); this.base = this.snapshot(); this.announce = ''; this.recount(); this.dirty = false; },
    });
</script>
@endpush
</x-dhiran-layout>
