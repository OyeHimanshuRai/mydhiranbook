<x-dhiran-layout title="Add Old Loan">
    @vite('resources/css/dhiran-forms.css')
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Add Old Loan</h1>
            <p class="text-sm text-gray-500 mt-1">A loan already running in your paper book, entered as it stands today. Nothing goes into the cash book.</p>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Old Loans" :href="route('dhiran.old-loans.index')" />
        </div>
    </x-dhiran.page-header>

    @php
        $oldItems = collect(old('items') ?: [[]])->values()->map(fn ($i) => [
            'description' => (string) ($i['description'] ?? ''), 'metal_type' => (string) ($i['metal_type'] ?? 'gold'),
            'purity' => (string) ($i['purity'] ?? '22'), 'gross_weight' => (string) ($i['gross_weight'] ?? ''),
            'stone_weight' => (string) ($i['stone_weight'] ?? ''), 'rate_per_gram_at_pledge' => (string) ($i['rate_per_gram_at_pledge'] ?? ''),
            'market_value' => (string) ($i['market_value'] ?? ''),
        ]);
        $err = fn ($k) => $errors->first($k);
        // Refusals per pledged item (items.N.field), shown on that item's own card.
        $itemErrors = [];
        foreach ($errors->getMessages() as $k => $msgs) {
            if (preg_match('/^items\.(\d+)\./', $k, $m)) { $itemErrors[(int) $m[1]][] = $msgs[0]; }
        }
        // The server asks for a new borrower's name and mobile only when no existing customer is picked (required_without), so none is starred.
        $borrowerFields = [['first_name', 'First name', 'text'], ['last_name', 'Last name', 'text'], ['mobile', 'Mobile number', 'tel'], ['pan', 'PAN', 'text'], ['id_number', 'ID number (Aadhaar / other)', 'text'], ['address', 'Address', 'text']];
        // [name, label, type, default, required, unit-before, unit-after]
        $loanFields = [
            ['paper_loan_number', 'Ticket number in your book', 'text', '', true, null, null],
            ['loan_date', 'Date the loan was given', 'date', '', true, null, null],
            ['principal_amount', 'Amount lent', 'number', '', true, '₹', null],
            ['principal_owed', 'Principal still owed today', 'number', '', true, '₹', null],
            ['interest_rate_monthly', 'Interest rate', 'number', $settings->default_interest_rate_monthly, true, null, '% / month'],
            ['tenure_months', 'Tenure', 'number', $settings->default_tenure_months, true, null, 'months'],
            ['interest_paid_until', 'Interest paid up to', 'date', '', true, null, null],
            ['unpaid_interest', 'Interest unpaid since then, as per your book', 'number', '', false, '₹', null],
            ['penalty_rate_monthly', 'Penalty rate', 'number', $settings->default_penalty_rate_monthly, false, null, '% / month'],
            ['grace_period_days', 'Grace days after maturity', 'number', $settings->grace_period_days, false, null, 'days'],
            ['gold_rate_on_date', 'Gold rate used', 'number', '', false, '₹', '/ g'],
            ['silver_rate_on_date', 'Silver rate used', 'number', '', false, '₹', '/ g'],
        ];
        $anchorFor = function ($k) use ($loanFields) {
            if (str_starts_with($k, 'items')) return 'ol-step-items';
            if (str_starts_with($k, 'borrower.')) return 'borrower_' . substr($k, 9);
            return in_array($k, array_column($loanFields, 0), true) || in_array($k, ['customer_id', 'interest_type', 'notes'], true) ? $k : 'ol-form';
        };
    @endphp

    <div class="content-inner">
        <form method="POST" action="{{ route('dhiran.old-loans.store') }}" id="ol-form" class="fm-page" data-turbo-frame="_top"
              @submit="busy = !$event.defaultPrevented"
              @pageshow.window="busy = false" @turbo:submit-end="if (!$event.detail.success) busy = false"
              x-data="{
                  busy: false,
                  customerId: @js((string) old('customer_id', '')),
                  items: @js($oldItems),
                  itemErrors: @js((object) array_map(fn ($m) => implode(' ', array_unique($m)), $itemErrors)),
                  purities: { gold: [24, 22, 20, 18, 14], silver: [999, 958, 925, 900, 800] },
                  add() { this.items.push({ description: '', metal_type: 'gold', purity: '22', gross_weight: '', stone_weight: '', rate_per_gram_at_pledge: '', market_value: '' }) },
                  remove(i) { if (this.items.length > 1) this.items.splice(i, 1) },
                  confirmRemove(i) { const t = this.items[i]; if (!(t.description || '').trim() && !t.gross_weight && !t.market_value || window.confirm('Remove item ' + (i + 1) + '?')) this.remove(i) },
              }">
            @csrf <x-dhiran.once />

            @if($errors->any())
                <div class="fm-alert" role="alert" tabindex="-1" x-init="$nextTick(() => $el.focus())">
                    <x-icon name="circle-alert" size="20" />
                    <div>
                        <p class="fm-alert-title">The old loan was not added. Please fix:</p>
                        <ul>
                            @foreach($errors->keys() as $k)
                                <li><a href="#{{ $anchorFor($k) }}">{{ $errors->first($k) }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif


            {{-- 1 Borrower --}}
            <section class="fm-card" id="ol-step-borrower" aria-labelledby="ol-h-borrower">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">1</span>
                    <div>
                        <h2 class="fm-title" id="ol-h-borrower">Borrower</h2>
                        <p class="fm-desc">Choose someone you already have, or type a new customer.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <div class="fm-field">
                        <label for="customer_id" class="fm-label">Already a customer?</label>
                        <select name="customer_id" id="customer_id" x-model="customerId" class="fm-control" @if($err('customer_id')) aria-invalid="true" aria-describedby="err-customer_id" @endif>
                            <option value="">No, add a new customer</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}">{{ trim($c->first_name . ' ' . $c->last_name) }} · {{ $c->mobile }}</option>
                            @endforeach
                        </select>
                        @if($err('customer_id'))<p class="fm-error" id="err-customer_id" role="alert"><x-icon name="circle-alert" size="16" />{{ $err('customer_id') }}</p>@endif
                    </div>

                    <div class="fm-grid" x-show="customerId === ''">
                        @foreach($borrowerFields as [$f, $label, $type])
                            <div class="fm-field {{ $f === 'address' ? 'fm-wide' : '' }}">
                                <label for="borrower_{{ $f }}" class="fm-label">{{ $label }}</label>
                                <input type="{{ $type }}" name="borrower[{{ $f }}]" id="borrower_{{ $f }}" value="{{ old("borrower.{$f}") }}" class="fm-control" autocomplete="off"
                                       @if($f === 'mobile') inputmode="numeric" maxlength="10" placeholder="10 digits" @endif
                                       @if($f === 'pan') maxlength="10" autocapitalize="characters" spellcheck="false" placeholder="ABCDE1234F" @endif
                                       @if($err("borrower.{$f}")) aria-invalid="true" aria-describedby="err-borrower_{{ $f }}" @endif
                                       :disabled="customerId !== ''">
                                @if($err("borrower.{$f}"))<p class="fm-error" id="err-borrower_{{ $f }}" role="alert"><x-icon name="circle-alert" size="16" />{{ $err("borrower.{$f}") }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- 2 The loan as it stands in the paper book --}}
            <section class="fm-card" id="ol-step-loan" aria-labelledby="ol-h-loan">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">2</span>
                    <div>
                        <h2 class="fm-title" id="ol-h-loan">Loan in your book</h2>
                        <p class="fm-desc">Copy it as it stands today.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <div class="fm-grid">
                        @foreach($loanFields as [$f, $label, $type, $default, $req, $pre, $suf])
                            <div class="fm-field">
                                <label for="{{ $f }}" class="fm-label">{{ $label }}@if($req) <x-req />@endif</label>
                                @php
                                    $attrs = ($type === 'number' ? 'step="0.01" min="0" inputmode="decimal"' : '')
                                        . ($f === 'tenure_months' || $f === 'grace_period_days' ? ' step="1" inputmode="numeric"' : '')
                                        . ($type === 'date' ? ' max="' . today()->toDateString() . '"' : '');
                                    if ($f === 'tenure_months' || $f === 'grace_period_days') { $attrs = 'min="0" step="1" inputmode="numeric"'; }
                                    $hasErr = (bool) $err($f);
                                @endphp
                                @if($pre || $suf)
                                    <div class="fm-affix @if($hasErr) is-invalid @endif">
                                        @if($pre)<span class="fm-unit fm-unit--pre">{{ $pre }}</span>@endif
                                        <input type="{{ $type }}" name="{{ $f }}" id="{{ $f }}" value="{{ old($f, $default) }}" class="fm-control" {!! $attrs !!} @if($req) required aria-required="true" @endif
                                               @if($f === 'unpaid_interest') aria-describedby="hint-unpaid_interest{{ $hasErr ? ' err-' . $f : '' }}" @elseif($hasErr) aria-describedby="err-{{ $f }}" @endif @if($hasErr) aria-invalid="true" @endif>
                                        @if($suf)<span class="fm-unit fm-unit--suf">{{ $suf }}</span>@endif
                                    </div>
                                @else
                                    <input type="{{ $type }}" name="{{ $f }}" id="{{ $f }}" value="{{ old($f, $default) }}" class="fm-control" {!! $attrs !!} @if($req) required aria-required="true" @endif @if($type === 'text') autocomplete="off" @endif
                                           @if($hasErr) aria-invalid="true" aria-describedby="err-{{ $f }}" @endif>
                                @endif
                                @if($f === 'unpaid_interest')<p class="fm-limit" id="hint-unpaid_interest">Leave blank to work it out from the paid-up date at this rate.</p>@endif
                                @if($hasErr)<p class="fm-error" id="err-{{ $f }}" role="alert"><x-icon name="circle-alert" size="16" />{{ $err($f) }}</p>@endif
                            </div>
                        @endforeach
                        <div class="fm-field">
                            <label for="interest_type" class="fm-label">Interest type <x-req /></label>
                            <select name="interest_type" id="interest_type" class="fm-control" required aria-required="true">
                                @foreach(['flat' => 'Flat', 'daily' => 'Daily', 'compound' => 'Compound (monthly)'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('interest_type', $settings->default_interest_type ?? 'flat') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            {{-- 3 Pledged items: every loan has collateral --}}
            <section class="fm-card" id="ol-step-items" aria-labelledby="ol-h-items">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">3</span>
                    <div>
                        <h2 class="fm-title" id="ol-h-items">Pledged items</h2>
                        <p class="fm-desc">One card for each piece held against this loan.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    @if($err('items'))<p class="fm-error" role="alert"><x-icon name="circle-alert" size="16" />{{ $err('items') }}</p>@endif
                    <template x-for="(item, i) in items" :key="i">
                        <fieldset class="fm-item" :aria-labelledby="'ol-item-h-' + i">
                            <div class="fm-item-head">
                                <h3 :id="'ol-item-h-' + i" x-text="'Item ' + (i + 1)"></h3>
                                <button type="button" class="btn btn-secondary fm-remove" @click="confirmRemove(i)" x-show="items.length > 1" :aria-label="'Remove item ' + (i + 1)">
                                    <x-icon name="trash-2" size="16" /> Remove
                                </button>
                            </div>
                            <p class="fm-error" role="alert" x-show="itemErrors[i]" x-cloak><x-icon name="circle-alert" size="16" /><span x-text="itemErrors[i]"></span></p>
                            <div class="fm-grid">
                                <div class="fm-field fm-wide">
                                    <label class="fm-label" :for="'oi-desc-' + i">Description <x-req /></label>
                                    <input type="text" :id="'oi-desc-' + i" :name="`items[${i}][description]`" x-model="item.description" class="fm-control" required aria-required="true" placeholder="e.g. Gold chain" autocomplete="off">
                                </div>
                                <div class="fm-field">
                                    <label class="fm-label" :for="'oi-metal-' + i">Metal</label>
                                    <select :id="'oi-metal-' + i" :name="`items[${i}][metal_type]`" x-model="item.metal_type" class="fm-control">
                                        <option value="gold">Gold</option><option value="silver">Silver</option><option value="other">Other (appraised)</option>
                                    </select>
                                </div>
                                <div class="fm-field" x-show="item.metal_type !== 'other'">
                                    <label class="fm-label" :for="'oi-purity-' + i">Purity</label>
                                    <select :id="'oi-purity-' + i" :name="`items[${i}][purity]`" x-model="item.purity" class="fm-control" :disabled="item.metal_type === 'other'">
                                        <template x-for="p in (purities[item.metal_type] || [])" :key="p"><option :value="p" x-text="item.metal_type === 'gold' ? p + 'K' : p" :selected="String(p) === String(item.purity)"></option></template>
                                    </select>
                                </div>
                                <div class="fm-wide fm-grid fm-grid--pair" x-show="item.metal_type !== 'other'">
                                    <div class="fm-field">
                                        <label class="fm-label" :for="'oi-gross-' + i">Gross weight</label>
                                        <div class="fm-affix">
                                            <input type="number" inputmode="decimal" step="0.001" min="0" :id="'oi-gross-' + i" :name="`items[${i}][gross_weight]`" x-model="item.gross_weight" class="fm-control" placeholder="0.000" :disabled="item.metal_type === 'other'" @focus="$event.target.select()">
                                            <span class="fm-unit fm-unit--suf">g</span>
                                        </div>
                                    </div>
                                    <div class="fm-field">
                                        <label class="fm-label" :for="'oi-stone-' + i">Stone weight</label>
                                        <div class="fm-affix">
                                            <input type="number" inputmode="decimal" step="0.001" min="0" :id="'oi-stone-' + i" :name="`items[${i}][stone_weight]`" x-model="item.stone_weight" class="fm-control" placeholder="0.000" :disabled="item.metal_type === 'other'" @focus="$event.target.select()">
                                            <span class="fm-unit fm-unit--suf">g</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="fm-field fm-wide" x-show="item.metal_type !== 'other'">
                                    <label class="fm-label" :for="'oi-rate-' + i">Rate per gram at pledge</label>
                                    <div class="fm-affix">
                                        <span class="fm-unit fm-unit--pre">₹</span>
                                        <input type="number" inputmode="decimal" step="0.01" min="0" :id="'oi-rate-' + i" :name="`items[${i}][rate_per_gram_at_pledge]`" x-model="item.rate_per_gram_at_pledge" class="fm-control" placeholder="0.00" :disabled="item.metal_type === 'other'" @focus="$event.target.select()">
                                        <span class="fm-unit fm-unit--suf">/ g</span>
                                    </div>
                                </div>
                                <div class="fm-field fm-wide" x-show="item.metal_type === 'other'">
                                    <label class="fm-label" :for="'oi-value-' + i">Appraised value</label>
                                    <div class="fm-affix">
                                        <span class="fm-unit fm-unit--pre">₹</span>
                                        <input type="number" inputmode="decimal" step="0.01" min="0" :id="'oi-value-' + i" :name="`items[${i}][market_value]`" x-model="item.market_value" class="fm-control" placeholder="0.00" :disabled="item.metal_type !== 'other'" @focus="$event.target.select()">
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                    </template>
                    <button type="button" class="btn btn-secondary fm-add-item" @click="add(); $nextTick(() => document.getElementById('oi-desc-' + (items.length - 1))?.focus())"><x-icon name="plus" size="16" /> Add another item</button>
                </div>
            </section>

            {{-- 4 Notes --}}
            <section class="fm-card" id="ol-step-notes" aria-labelledby="ol-h-notes">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">4</span>
                    <div>
                        <h2 class="fm-title" id="ol-h-notes">Notes</h2>
                        <p class="fm-desc">Anything from the paper book worth keeping.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <div class="fm-field">
                        <label for="notes" class="fm-label">Notes</label>
                        <textarea name="notes" id="notes" rows="3" class="fm-control">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </section>

            <div class="fm-bar" role="region" aria-label="Save this old loan">
                <p class="fm-bar-text">Nothing is saved until you press the button.</p>
                <div class="fm-bar-acts">
                    <a href="{{ route('dhiran.old-loans.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy.toString()"><x-icon name="save" size="16" /> <span x-text="busy ? 'Saving…' : 'Bring in loan'">Bring in loan</span></button>
                </div>
            </div>
        </form>
    </div>
</x-dhiran-layout>
