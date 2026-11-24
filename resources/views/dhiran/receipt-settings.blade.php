{{-- Receipt settings: one receipt at a time (Pledge | Payment), each field a switch row inside a titled group. A switched-on
     field is posted as show[] (the controller stores everything else as hidden). No JS: both receipts stack, all controls still post. --}}
@php
    use App\Models\Dhiran\DhiranSettings;
    $receipts = DhiranSettings::RECEIPT_FIELDS;
    $lead = ['pledge' => 'Given to the borrower when a loan is made.', 'payment' => 'Given every time a payment is received.', 'notice' => 'Sent when a loan is overdue and the notice is issued.'];
    $total = fn ($def) => collect($def['groups'])->sum(fn ($f) => count($f));
    $on = fn ($receipt, $fields) => collect(array_keys($fields))->filter(fn ($k) => in_array("$receipt.$k", $printed, true))->count();
@endphp
<x-dhiran-layout title="Receipt settings" settings footer>
    <x-dhiran.page-header>
        <x-dhiran.settings-nav active="receipt" as="title" />
    </x-dhiran.page-header>
<turbo-frame id="settings-pane" data-turbo-action="advance">
<div class="content-inner dhiran-settings-root rs">
<x-dhiran.settings-nav active="receipt" switcher />
<form id="rs-form" class="rs-form" method="POST" action="{{ route('dhiran.settings.receipt') }}" data-turbo-frame="_top"
      x-data="rsReceiptSettings({{ \Illuminate\Support\Js::from(['dirty' => session()->hasOldInput(), 'saved' => session('success')]) }})"
      @input="touch($event)" @change="touch($event)" @submit="submit($event)" @beforeunload.window="if (dirty && !saving) $event.preventDefault()">
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
            <button type="submit" class="btn btn-primary rs-save" disabled :disabled="saving || !dirty" :aria-busy="saving"><x-icon name="save" size="16" /> <span x-text="saving ? 'Saving…' : 'Save changes'">Save changes</span></button>
        </div>
    </div>
</form>
</div>
</turbo-frame>

</x-dhiran-layout>
