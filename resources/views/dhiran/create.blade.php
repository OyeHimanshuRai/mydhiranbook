<x-dhiran-layout title="New Loan">
    @vite('resources/css/dhiran-forms.css')
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">New Pledge Loan</h1>
            <p class="text-sm text-gray-500 mt-1">Create a new gold &amp; silver pledge loan</p>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Back to Loans" :href="route('dhiran.loans')" />
        </div>
    </x-dhiran.page-header>

    @php
        $sym = $currencySymbol ?? '₹';
        // Server refusals per pledged item (items.N.field), handed to the Alpine items so each card shows its own.
        $itemErrors = [];
        foreach ($errors->getMessages() as $k => $msgs) {
            if (preg_match('/^items\.(\d+)\./', $k, $m)) { $itemErrors[(int) $m[1]][] = $msgs[0]; }
        }
        $fieldIds = ['principal_amount', 'gold_rate_on_date', 'interest_rate_monthly', 'interest_type', 'advance_interest_months', 'tenure_months', 'penalty_rate_monthly', 'notes'];
        $anchorFor = fn ($k) => match (true) {
            $k === 'customer_id' => 'fm-customer-search',
            str_starts_with($k, 'items') => 'fm-step-items',
            in_array($k, ['pan', 'aadhaar'], true) => 'fm-step-kyc',
            in_array($k, $fieldIds, true) => $k,
            default => 'fm-form',
        };
    @endphp

    <div class="content-inner"
         x-data="dhiranCreateForm()"
         x-effect="document.body.classList.toggle('dh-modal-open', newBorrowerOpen)"
         @turbo:before-cache.window="document.body.classList.remove('dh-modal-open')"
         @pageshow.window="submitting = false">

        <form method="POST" action="{{ route('dhiran.store') }}" id="fm-form" class="fm-page dhiran-create-form" @submit="prepareSubmit($event)" @turbo:submit-end="if (!$event.detail.success) submitting = false" data-turbo-frame="_top">
            @csrf <x-dhiran.once />

            @if($errors->any())
                <div class="fm-alert" role="alert" tabindex="-1" x-init="$nextTick(() => $el.focus())">
                    <x-icon name="circle-alert" size="20" />
                    <div>
                        <p class="fm-alert-title">The loan was not created. Please fix:</p>
                        <ul>
                            @foreach($errors->keys() as $k)
                                <li><a href="#{{ $anchorFor($k) }}">{{ $errors->first($k) }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif



            {{-- 1 Customer --}}
            <section class="fm-card" id="fm-step-customer" aria-labelledby="fm-h-customer">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">1</span>
                    <div>
                        <h2 class="fm-title" id="fm-h-customer">Customer</h2>
                        <p class="fm-desc">Who is pledging? Search the borrowers you have saved, or add a new one.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <input type="hidden" name="customer_id" :value="customer_id">
                    <div class="fm-field fm-combo" @click.outside="customerDropdownOpen = false">
                        <label class="fm-label" for="fm-customer-search">Borrower <x-req /></label>
                        <div class="fm-affix" :class="{ 'is-invalid': {{ $errors->has('customer_id') ? 'true' : 'false' }} && !selectedCustomer }">
                            <span class="fm-unit fm-unit--pre" aria-hidden="true"><x-icon name="search" size="16" /></span>
                            <input type="search" id="fm-customer-search" class="fm-control"
                                   placeholder="Name, mobile or address" autocomplete="off" autocapitalize="off" spellcheck="false"
                                   role="combobox" aria-required="true" aria-autocomplete="list" aria-controls="fm-customer-list" :aria-expanded="customerDropdownOpen.toString()"
                                   @if($errors->has('customer_id')) aria-invalid="true" aria-describedby="err-customer_id" @endif
                                   x-model="customerSearch"
                                   @input="handleCustomerSearchInput()"
                                   @keydown.escape="customerDropdownOpen = false"
                                   @keydown.arrow-down.prevent="if (customerSearch.trim()) { customerDropdownOpen = true; $nextTick(() => $refs.custList.querySelector('button')?.focus()) }">
                        </div>
                        <ul class="fm-combo-list" id="fm-customer-list" role="listbox" aria-label="Borrowers" x-ref="custList" x-show="customerDropdownOpen" x-cloak
                            @keydown.escape="customerDropdownOpen = false; document.getElementById('fm-customer-search').focus()"
                            @keydown.arrow-down.prevent="$event.target.closest('li')?.nextElementSibling?.querySelector('button')?.focus()"
                            @keydown.arrow-up.prevent="($event.target.closest('li')?.previousElementSibling?.querySelector('button') || document.getElementById('fm-customer-search'))?.focus()">
                            <template x-for="c in results" :key="c.id">
                                <li role="presentation">
                                    <button type="button" role="option" class="fm-combo-opt" @click="selectCustomer(c)">
                                        <span class="fm-combo-name" x-text="c.name"></span>
                                        <span class="fm-combo-sub" x-text="[c.mobile, c.address].filter(Boolean).join(' · ') || 'No profile details added'"></span>
                                    </button>
                                </li>
                            </template>
                            <li class="fm-combo-none" x-show="searchState !== 'done' || results.length === 0" role="presentation" aria-live="polite" x-text="searchState === 'loading' ? 'Searching…' : (searchState === 'failed' ? 'Could not search. Check your connection and try again.' : (searchState === 'done' ? 'No borrower matches. Check the spelling or add a new borrower.' : 'Type at least 2 letters or digits.'))"></li>
                        </ul>
                        @error('customer_id')
                            <p class="fm-error" id="err-customer_id" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="button" class="btn btn-secondary fm-add-borrower" @click="openNewBorrower()">
                        <x-icon name="user-plus" size="16" /> Add new borrower
                    </button>

                    <div class="fm-picked" x-show="selectedCustomer" x-cloak aria-live="polite">
                        <p class="fm-picked-name" x-text="selectedCustomer && selectedCustomer.name ? selectedCustomer.name : 'Borrower'"></p>
                        <dl class="fm-facts">
                            <div><dt>Mobile</dt><dd x-text="selectedCustomer && selectedCustomer.mobile ? selectedCustomer.mobile : 'Not added'"></dd></div>
                            <div><dt>State code</dt><dd x-text="selectedCustomer && selectedCustomer.state_code ? selectedCustomer.state_code : 'Not added'"></dd></div>
                            <div class="fm-wide"><dt>Address</dt><dd x-text="selectedCustomer && selectedCustomer.address ? selectedCustomer.address : 'Not added'"></dd></div>
                        </dl>
                    </div>
                    <p class="fm-note" x-show="!selectedCustomer" x-cloak>No borrower chosen yet. Their details will show here.</p>
                </div>
            </section>

            {{-- 2 Pledged items --}}
            <section class="fm-card" id="fm-step-items" aria-labelledby="fm-h-items">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">2</span>
                    <div>
                        <h2 class="fm-title" id="fm-h-items">Pledged items</h2>
                        <p class="fm-desc">One card for each piece the customer leaves with you.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <template x-for="(item, index) in items" :key="index">
                        <fieldset class="fm-item" :id="'fm-item-' + index" :aria-labelledby="'fm-item-h-' + index">
                            <div class="fm-item-head">
                                <h3 :id="'fm-item-h-' + index" x-text="'Item ' + (index + 1)"></h3>
                                <button type="button" class="btn btn-secondary fm-remove" x-show="items.length > 1" @click="confirmRemove(index)" :aria-label="'Remove item ' + (index + 1)">
                                    <x-icon name="trash-2" size="16" /> Remove
                                </button>
                            </div>
                            <p class="fm-error" role="alert" x-show="itemErr(index)" x-cloak><x-icon name="circle-alert" size="16" /><span x-text="itemErr(index)"></span></p>

                            <div class="fm-grid">
                                <div class="fm-field fm-wide">
                                    <label class="fm-label" :for="'it-desc-' + index">Description <x-req /></label>
                                    <input type="text" :id="'it-desc-' + index" :name="'items['+index+'][description]'" x-model="item.description" data-item-desc
                                           placeholder="e.g. Gold chain, silver anklet" class="fm-control" required aria-required="true" autocomplete="off">
                                </div>
                                <div class="fm-field">
                                    <label class="fm-label" :for="'it-metal-' + index">Metal</label>
                                    <select :id="'it-metal-' + index" :name="'items['+index+'][metal_type]'" x-model="item.metal_type" class="fm-control" @change="onMetalChange(index)">
                                        <option value="gold">Gold</option>
                                        <option value="silver">Silver</option>
                                        <option value="other">Other (diamond, platinum, watch…)</option>
                                    </select>
                                </div>
                                {{-- Valuation: metal-melt (auto from purity × rate) OR appraised (operator enters value). 'Other' is always appraised. --}}
                                <div class="fm-field" x-show="item.metal_type !== 'other'">
                                    <label class="fm-label" :for="'it-mode-' + index">Valuation</label>
                                    <select :id="'it-mode-' + index" :name="'items['+index+'][value_mode]'" x-model="item.value_mode" class="fm-control" @change="recalcItem(index)">
                                        <option value="metal">Auto (metal value)</option>
                                        <option value="appraised">Appraised (enter value)</option>
                                    </select>
                                </div>

                                {{-- Metal-mode fields (gold/silver, auto valuation) --}}
                                <template x-if="item.metal_type !== 'other' && item.value_mode !== 'appraised'">
                                    <div class="fm-field">
                                        <label class="fm-label" :for="'it-purity-' + index">Purity</label>
                                        <select :id="'it-purity-' + index" :name="'items['+index+'][purity]'" x-model.number="item.purity" class="fm-control" @change="recalcItem(index)">
                                            <option value="">Select</option>
                                            <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
                                                {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
                                                <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>
                                <template x-if="item.metal_type !== 'other' && item.value_mode !== 'appraised'">
                                    <div class="fm-field">
                                        <label class="fm-label" :for="'it-rate-' + index">Rate per gram at pledge</label>
                                        <div class="fm-affix">
                                            <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                            <input type="number" inputmode="decimal" step="0.01" min="0" :id="'it-rate-' + index" :name="'items['+index+'][rate_per_gram_at_pledge]'" x-model.number="item.rate_per_gram_at_pledge"
                                                   placeholder="0.00" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                        </div>
                                    </div>
                                </template>

                                <div class="fm-field" x-show="item.metal_type === 'other' || item.value_mode === 'appraised'">
                                    <label class="fm-label" :for="'it-value-' + index">Appraised value</label>
                                    <div class="fm-affix">
                                        <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                        <input type="number" inputmode="decimal" step="0.01" min="0" :id="'it-value-' + index" :name="'items['+index+'][market_value]'"
                                               x-model.number="item.market_value" placeholder="0.00" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                    </div>
                                </div>

                                <div class="fm-wide fm-grid fm-grid--pair" x-show="item.metal_type !== 'other'">
                                <div class="fm-field">
                                    <label class="fm-label" :for="'it-gross-' + index">Gross weight</label>
                                    <div class="fm-affix">
                                        <input type="number" inputmode="decimal" step="0.001" min="0" :id="'it-gross-' + index" :name="'items['+index+'][gross_weight]'" x-model.number="item.gross_weight"
                                               placeholder="0.000" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                        <span class="fm-unit fm-unit--suf">g</span>
                                    </div>
                                </div>
                                <div class="fm-field">
                                    <label class="fm-label" :for="'it-stone-' + index">Stone weight</label>
                                    <div class="fm-affix">
                                        <input type="number" inputmode="decimal" step="0.001" min="0" :id="'it-stone-' + index" :name="'items['+index+'][stone_weight]'" x-model.number="item.stone_weight"
                                               placeholder="0.000" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                        <span class="fm-unit fm-unit--suf">g</span>
                                    </div>
                                </div>
                                </div>
                                <div class="fm-field fm-wide">
                                    <label class="fm-label" :for="'it-huid-' + index">HUID</label>
                                    <input type="text" :id="'it-huid-' + index" :name="'items['+index+'][huid]'" x-model="item.huid"
                                           placeholder="6-character hallmark ID" class="fm-control" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false">
                                </div>
                            </div>

                            {{-- What the item is worth, worked out as you type. --}}
                            <dl class="fm-readout" aria-live="polite">
                                <div x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"><dt>Net metal weight</dt><dd x-text="item.net_metal_weight.toFixed(3) + ' g'"></dd></div>
                                <div x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"><dt>Fine weight</dt><dd x-text="item.fine_weight.toFixed(3) + ' g'"></dd></div>
                                <div><dt>Market value</dt><dd x-text="'{{ $sym }}' + money(item.market_value)"></dd></div>
                                <div><dt>Loan value</dt><dd x-text="'{{ $sym }}' + money(item.loan_value)"></dd></div>
                            </dl>
                            <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                            {{-- Hidden carriers so server always gets net/fine even when the weights are hidden (0 for appraised/other). --}}
                            <input type="hidden" :name="'items['+index+'][net_metal_weight]'" :value="item.net_metal_weight">
                            <input type="hidden" :name="'items['+index+'][fine_weight]'" :value="item.fine_weight">
                        </fieldset>
                    </template>

                    <button type="button" class="btn btn-secondary fm-add-item" @click="addItem(); $nextTick(() => focusItem(items.length - 1))">
                        <x-icon name="plus" size="16" /> Add another item
                    </button>
                </div>
            </section>

            {{-- 3 Loan terms --}}
            <section class="fm-card" id="fm-step-terms" aria-labelledby="fm-h-terms">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">3</span>
                    <div>
                        <h2 class="fm-title" id="fm-h-terms">Loan terms</h2>
                        <p class="fm-desc">Amount, interest and period.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <div class="fm-grid">
                        <div class="fm-field fm-wide">
                            <label for="principal_amount" class="fm-label">Principal amount <x-req /></label>
                            <div class="fm-affix fm-affix--lead" :class="{ 'is-invalid': principalProblem }">
                                <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                <input type="number" inputmode="decimal" step="0.01" name="principal_amount" id="principal_amount"
                                       x-model.number="principal_amount"
                                       value="{{ old('principal_amount') }}"
                                       placeholder="0.00" class="fm-control" required aria-required="true" @focus="$event.target.select()"
                                       :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit @error('principal_amount') err-principal_amount @enderror">
                            </div>
                            <p id="principal-limit" class="fm-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                            @error('principal_amount')
                                <p class="fm-error" id="err-principal_amount" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                            <dl class="fm-ltv">
                                <div><dt x-text="'Max loan at ' + ltvPercent + '% LTV'"></dt><dd x-text="'{{ $sym }}' + money(maxPrincipal)"></dd></div>
                            </dl>
                        </div>
                        <div class="fm-field">
                            <label for="interest_rate_monthly" class="fm-label">Interest rate</label>
                            <div class="fm-affix">
                                <input type="number" inputmode="decimal" step="0.01" name="interest_rate_monthly" id="interest_rate_monthly"
                                       x-model.number="interest_rate_monthly"
                                       value="{{ old('interest_rate_monthly', $defaults['interest_rate_monthly'] ?? '') }}"
                                       placeholder="e.g. 1.5" class="fm-control" @focus="$event.target.select()"
                                       @error('interest_rate_monthly') aria-invalid="true" aria-describedby="err-interest_rate_monthly" @enderror>
                                <span class="fm-unit fm-unit--suf">% / month</span>
                            </div>
                            @error('interest_rate_monthly')
                                <p class="fm-error" id="err-interest_rate_monthly" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="fm-field">
                            <label for="interest_type" class="fm-label">Interest type</label>
                            <select name="interest_type" id="interest_type" x-model="interest_type" class="fm-control"
                                    @error('interest_type') aria-invalid="true" aria-describedby="err-interest_type" @enderror>
                                <option value="flat">Flat (Simple)</option>
                                <option value="daily">Daily</option>
                                <option value="compound">Compound</option>
                            </select>
                            @error('interest_type')
                                <p class="fm-error" id="err-interest_type" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                        @if($settings->advance_interest_enabled)
                        <div class="fm-field">
                            <label for="advance_interest_months" class="fm-label">Interest collection</label>
                            <select name="advance_interest_months" id="advance_interest_months" x-model.number="advance_interest_months" class="fm-control"
                                    @error('advance_interest_months') aria-invalid="true" aria-describedby="err-advance_interest_months" @enderror>
                                <option value="0">Collected later, as it builds up</option>
                                @foreach([1 => 'First month taken now', 2 => 'First 2 months taken now', 3 => 'First 3 months taken now'] as $m => $label)
                                    <option value="{{ $m }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('advance_interest_months')
                                <p class="fm-error" id="err-advance_interest_months" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                        @endif
                        <div class="fm-field">
                            <label for="tenure_months" class="fm-label">Tenure</label>
                            <div class="fm-affix">
                                <input type="number" inputmode="numeric" name="tenure_months" id="tenure_months"
                                       x-model.number="tenure_months"
                                       value="{{ old('tenure_months', $defaults['tenure_months'] ?? 6) }}"
                                       placeholder="6" class="fm-control" min="1" step="1" @focus="$event.target.select()"
                                       @error('tenure_months') aria-invalid="true" aria-describedby="err-tenure_months" @enderror>
                                <span class="fm-unit fm-unit--suf">months</span>
                            </div>
                            @error('tenure_months')
                                <p class="fm-error" id="err-tenure_months" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="fm-field">
                            <label for="penalty_rate_monthly" class="fm-label">Penalty rate</label>
                            <div class="fm-affix">
                                <input type="number" inputmode="decimal" step="0.01" name="penalty_rate_monthly" id="penalty_rate_monthly"
                                       x-model.number="penalty_rate_monthly"
                                       value="{{ old('penalty_rate_monthly', $defaults['penalty_rate_monthly'] ?? '') }}"
                                       placeholder="e.g. 2.0" class="fm-control" @focus="$event.target.select()"
                                       @error('penalty_rate_monthly') aria-invalid="true" aria-describedby="err-penalty_rate_monthly" @enderror>
                                <span class="fm-unit fm-unit--suf">% / month</span>
                            </div>
                            @error('penalty_rate_monthly')
                                <p class="fm-error" id="err-penalty_rate_monthly" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="fm-field">
                            <label for="gold_rate_on_date" class="fm-label">Gold rate today</label>
                            <div class="fm-affix">
                                <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                <input type="number" inputmode="decimal" step="0.01" name="gold_rate_on_date" id="gold_rate_on_date"
                                       x-model.number="goldRateOnDate"
                                       value="{{ old('gold_rate_on_date') }}"
                                       placeholder="Reference rate" class="fm-control" @focus="$event.target.select()"
                                       @error('gold_rate_on_date') aria-invalid="true" aria-describedby="err-gold_rate_on_date" @enderror>
                                <span class="fm-unit fm-unit--suf">/ g</span>
                            </div>
                            @error('gold_rate_on_date')
                                <p class="fm-error" id="err-gold_rate_on_date" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                        {{-- the server requires the silver reference rate when any silver item is valued by metal weight --}}
                        <div class="fm-field" x-cloak x-show="items.some(i => i.metal_type === 'silver' && i.value_mode !== 'appraised')">
                            <label for="silver_rate_on_date" class="fm-label">Silver rate today</label>
                            <div class="fm-affix">
                                <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                <input type="number" inputmode="decimal" step="0.01" name="silver_rate_on_date" id="silver_rate_on_date"
                                       value="{{ old('silver_rate_on_date') }}"
                                       placeholder="Reference rate" class="fm-control" @focus="$event.target.select()"
                                       @error('silver_rate_on_date') aria-invalid="true" aria-describedby="err-silver_rate_on_date" @enderror>
                                <span class="fm-unit fm-unit--suf">/ g</span>
                            </div>
                            @error('silver_rate_on_date')
                                <p class="fm-error" id="err-silver_rate_on_date" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <h3 class="fm-sub">Loan summary</h3>
                    <dl class="fm-sum" aria-live="polite">
                        <div><dt>Total market value</dt><dd x-text="'{{ $sym }}' + money(totalMarketValue)"></dd></div>
                        <div><dt>Total loan value (from items)</dt><dd x-text="'{{ $sym }}' + money(totalLoanValue)"></dd></div>
                        <div class="fm-sum-main"><dt>Principal amount</dt><dd x-text="'{{ $sym }}' + money((principal_amount || 0))"></dd></div>
                        <div><dt>Monthly interest (approx.)</dt><dd x-text="'{{ $sym }}' + money(monthlyInterest)"></dd></div>
                        <div>
                            <dt>Processing fee</dt>
                            <dd x-text="'{{ $sym }}' + money(processingFee)"></dd>
                            <input type="hidden" name="processing_fee" :value="processingFee">
                        </div>
                        @if($settings->advance_interest_enabled)
                        <div x-show="advanceInterest > 0" x-cloak>
                            <dt>Interest taken now</dt>
                            <dd x-text="'{{ $sym }}' + money(advanceInterest)"></dd>
                        </div>
                        <div class="fm-sum-main" x-show="advanceInterest > 0" x-cloak>
                            <dt>Cash to hand over</dt>
                            <dd x-text="'{{ $sym }}' + money((Number(principal_amount) || 0) - advanceInterest - processingFee)"></dd>
                        </div>
                        @endif
                    </dl>
                </div>
            </section>

            {{-- 4 KYC & notes --}}
            <section class="fm-card" id="fm-step-kyc" aria-labelledby="fm-h-kyc">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">4</span>
                    <div>
                        <h2 class="fm-title" id="fm-h-kyc">KYC &amp; notes</h2>
                        <p class="fm-desc">PAN and ID come from the borrower's saved profile.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <input type="hidden" name="pan" x-model="loanPan">
                    <input type="hidden" name="aadhaar" x-model="loanAadhaar">
                    <dl class="fm-facts">
                        <div><dt>PAN</dt><dd x-text="loanPan ? loanPan : (selectedCustomer ? 'Not added' : 'Choose a borrower first')"></dd></div>
                        <div><dt>ID number</dt><dd x-text="loanAadhaar ? loanAadhaar : (selectedCustomer ? 'Not added' : 'Choose a borrower first')"></dd></div>
                    </dl>
                    @error('pan')
                        <p class="fm-error" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                    @enderror
                    @error('aadhaar')
                        <p class="fm-error" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                    @enderror
                    <div class="fm-field">
                        <label for="notes" class="fm-label">Notes</label>
                        <textarea name="notes" id="notes" rows="3" placeholder="Anything else to remember about this loan" class="fm-control">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </section>

            {{-- Always in reach: the three figures that matter and the way to finish. --}}
            <div class="fm-bar" role="region" aria-label="Loan totals and create">
                <dl class="fm-bar-figs">
                    <div><dt>Principal</dt><dd x-text="'{{ $sym }}' + money((principal_amount || 0))"></dd></div>
                    <div><dt>Max loan</dt><dd x-text="'{{ $sym }}' + money(maxPrincipal)"></dd></div>
                    <div><dt>Items total</dt><dd x-text="'{{ $sym }}' + money(totalMarketValue)"></dd></div>
                </dl>
                <div class="fm-bar-acts">
                    <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting.toString()">
                        <x-icon name="save" size="16" /> <span x-text="submitting ? 'Creating…' : 'Create loan'">Create loan</span>
                    </button>
                </div>
            </div>
        </form>

        {{-- Add new borrower: a full-height sheet on phones, a centred dialog from 768px. The native dialog gives the focus trap, Esc and the scrim. --}}
        <dialog class="fm-dialog" x-ref="nbDialog" aria-labelledby="fm-nb-title"
                x-effect="newBorrowerOpen ? ($refs.nbDialog.open || $refs.nbDialog.showModal()) : ($refs.nbDialog.open && $refs.nbDialog.close())"
                @cancel.prevent="closeNewBorrower()"
                @click.self="closeNewBorrower()"
                @keydown.enter="if (!$event.target.closest('textarea, button')) { $event.preventDefault(); saveBorrower(); }">
            <div class="fm-dialog-card">
                <div class="fm-dialog-head">
                    <div>
                        <h2 id="fm-nb-title">Add new borrower</h2>
                        <p>Saved to your borrowers and chosen for this loan.</p>
                    </div>
                    <button type="button" class="btn btn-secondary fm-dialog-x" @click="closeNewBorrower()" :disabled="nbSaving" title="Close">
                        <x-icon name="x" size="18" /><span class="fm-sr">Close</span>
                    </button>
                </div>
                <div class="fm-dialog-body">
                    <p class="fm-alert fm-alert--inline" role="alert" x-show="nbError" x-cloak><x-icon name="circle-alert" size="20" /><span x-text="nbError"></span></p>
                    <div class="fm-grid">
                        <div class="fm-field">
                            <label class="fm-label" for="nb-first_name">First name <x-req /></label>
                            <input type="text" id="nb-first_name" class="fm-control" x-model="nb.first_name" aria-required="true" autocomplete="off" maxlength="255" :aria-invalid="nbField === 'first_name' ? 'true' : null" autofocus>
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="nb-last_name">Last name</label>
                            <input type="text" id="nb-last_name" class="fm-control" x-model="nb.last_name" autocomplete="off" maxlength="255">
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="nb-mobile">Mobile number <x-req /></label>
                            <input type="tel" id="nb-mobile" class="fm-control" inputmode="numeric" placeholder="10 digits" maxlength="10" autocomplete="off" aria-required="true" x-model="nb.mobile" :aria-invalid="nbField === 'mobile' ? 'true' : null">
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="nb-pan">PAN</label>
                            <input type="text" id="nb-pan" class="fm-control" placeholder="ABCDE1234F" maxlength="10" autocomplete="off" autocapitalize="characters" spellcheck="false" x-model="nb.pan" @input="nb.pan = nb.pan.toUpperCase()">
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="nb-id_number">ID number</label>
                            <input type="text" id="nb-id_number" class="fm-control" autocomplete="off" x-model="nb.id_number">
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="nb-state_code">State code</label>
                            <input type="text" id="nb-state_code" class="fm-control" autocomplete="off" x-model="nb.state_code">
                        </div>
                        <div class="fm-field fm-wide">
                            <label class="fm-label" for="nb-address">Address</label>
                            <textarea id="nb-address" class="fm-control" rows="3" autocomplete="off" x-model="nb.address"></textarea>
                        </div>
                    </div>
                </div>
                <div class="fm-dialog-foot">
                    <button type="button" class="btn btn-secondary" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
                    <button type="button" class="btn btn-primary" @click="saveBorrower()" :disabled="nbSaving" :aria-busy="nbSaving.toString()">
                        <span x-text="nbSaving ? 'Saving…' : 'Save borrower'">Save borrower</span>
                    </button>
                </div>
            </div>
        </dialog>
    </div>

    @push('scripts')
    <script>
        (() => {
        const registerDhiranCreateForm = () => {
            Alpine.data('dhiranCreateForm', () => ({
                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
                preselected: {{ Illuminate\Support\Js::from($preselected ?? null) }},
                get customer_id() {
                    return this.selectedCustomer ? this.selectedCustomer.id : '';
                },
                customerDisplayName: '',
                customerDropdownOpen: false,
                customerSearch: '',
                // Matches for what was typed, fetched from the server (8 at most): the page never carries the borrower book.
                results: [],
                searchState: 'idle', // idle | loading | done | failed
                searchTimer: null,
                searchSeq: 0,
                selectedCustomer: null,
                // Add Borrower dialog: one state, 'closed' -> 'open' -> 'saving' -> 'closed' (saved) or 'open' (refused).
                nbState: 'closed',
                nbError: '',
                nbField: '',
                submitting: false,
                itemErrors: {{ Illuminate\Support\Js::from((object) array_map(fn ($m) => implode(' ', array_unique($m)), $itemErrors)) }},
                get newBorrowerOpen() {
                    return this.nbState !== 'closed';
                },
                get nbSaving() {
                    return this.nbState === 'saving';
                },
                nb: { first_name: '', last_name: '', mobile: '', pan: '', id_number: '', state_code: '', address: '' },
                loanPan: {{ Illuminate\Support\Js::from(old('pan', '')) }},
                loanAadhaar: {{ Illuminate\Support\Js::from(old('aadhaar', '')) }},
                goldRateOnDate: {{ old('gold_rate_on_date', 0) }},
                principal_amount: {{ old('principal_amount', 0) }},
                interest_rate_monthly: {{ old('interest_rate_monthly', $defaults['interest_rate_monthly'] ?? 1.5) }},
                interest_type: '{{ old('interest_type', $defaults['interest_type'] ?? 'flat') }}',
                advance_interest_months: {{ (int) old('advance_interest_months', $settings->advance_interest_enabled ? $settings->advance_interest_default_months : 0) }},
                tenure_months: {{ old('tenure_months', $defaults['tenure_months'] ?? 6) }},
                penalty_rate_monthly: {{ old('penalty_rate_monthly', $defaults['penalty_rate_monthly'] ?? 2) }},
                processingFeePercent: {{ $defaults['processing_fee_percent'] ?? 0 }},
                processingFeeFlat: {{ $defaults['processing_fee_flat'] ?? 0 }},
                ltvPercent: {{ $defaults['ltv_percent'] ?? 75 }},
                highValueLtv: {{ $defaults['high_value_ltv'] ?? 75 }},
                highValueThreshold: {{ $defaults['high_value_threshold'] ?? 250000 }},
                minLoan: {{ $defaults['min_loan'] ?? 0 }},
                maxLoan: {{ $defaults['max_loan'] ?? 0 }},

                // After a refused save, the items come back as typed (init() recalculates their values).
                items: {{ Illuminate\Support\Js::from(collect(old('items') ?: [[]])->values()->map(fn ($i) => [
                    'description' => (string) ($i['description'] ?? ''),
                    'metal_type' => (string) ($i['metal_type'] ?? 'gold'),
                    'value_mode' => (string) ($i['value_mode'] ?? 'metal'),
                    'gross_weight' => (float) ($i['gross_weight'] ?? 0),
                    'stone_weight' => (float) ($i['stone_weight'] ?? 0),
                    'purity' => (float) ($i['purity'] ?? 22),
                    'rate_per_gram_at_pledge' => (float) ($i['rate_per_gram_at_pledge'] ?? 0),
                    'huid' => (string) ($i['huid'] ?? ''),
                    'net_metal_weight' => 0, 'fine_weight' => 0,
                    'market_value' => (float) ($i['market_value'] ?? 0), 'loan_value' => 0,
                ])) }},

                get totalMarketValue() {
                    return this.items.reduce((sum, i) => sum + (i.market_value || 0), 0);
                },
                get totalLoanValue() {
                    return this.items.reduce((sum, i) => sum + (i.loan_value || 0), 0);
                },
                // Step marks for the strip at the top: a hint of progress, never a gate (the server still validates).
                get customerDone() { return !!this.selectedCustomer; },
                get itemsDone() { return this.items.length > 0 && this.items.every(i => (i.description || '').trim() && (i.market_value || 0) > 0); },
                get termsDone() { return (Number(this.principal_amount) || 0) > 0 && (Number(this.interest_rate_monthly) || 0) > 0 && (Number(this.tenure_months) || 0) > 0 && !this.principalProblem; },
                // Largest principal the server will accept (mirrors DhiranService::createLoan): the items' market
                // value at the LTV for the principal's tier (each item rounded to paise), never above the shop's
                // maximum. A principal at or over the high-value threshold gets the (usually lower) high-value LTV.
                itemsLoanValueAt(ltv) {
                    return this.items.reduce((sum, i) => sum + Math.round((i.market_value || 0) * ltv) / 100, 0);
                },
                get maxPrincipal() {
                    const T = this.highValueThreshold, low = this.itemsLoanValueAt(this.ltvPercent), high = this.itemsLoanValueAt(this.highValueLtv);
                    const best = T <= 0 ? high : (high >= T ? Math.max(high, Math.min(low, T - 0.01)) : Math.min(low, T - 0.01));
                    return Math.max(0, this.maxLoan > 0 ? Math.min(best, this.maxLoan) : best);
                },
                get principalProblem() {
                    const p = Number(this.principal_amount) || 0;
                    if (p <= 0 || this.totalMarketValue <= 0) return false;
                    return p > this.maxPrincipal + 0.001 || p < this.minLoan;
                },
                get principalHint() {
                    if (this.totalMarketValue <= 0) return 'Add the pledged items to see how much can be lent.';
                    const p = Number(this.principal_amount) || 0, sym = '{{ $currencySymbol ?? '₹' }}';
                    if (p > 0 && p < this.minLoan) return 'The smallest loan is ' + sym + this.money(this.minLoan) + '.';
                    if (p > this.maxPrincipal + 0.001) return 'Too high: these items allow up to ' + sym + this.money(this.maxPrincipal) + '.';
                    return 'Up to ' + sym + this.money(this.maxPrincipal) + ' against these items.';
                },
                get monthlyInterest() {
                    return (this.principal_amount || 0) * (this.interest_rate_monthly || 0) / 100;
                },
                get advanceInterest() {
                    // first N months at the loan's monthly rate, to the paisa (the server computes the same figure)
                    return Math.round((Number(this.principal_amount) || 0) * (Number(this.interest_rate_monthly) || 0) * (Number(this.advance_interest_months) || 0)) / 100;
                },
                get processingFee() {
                    // The shop's fee is either a % of the loan or a fixed amount (Dhiran settings).
                    return (Number(this.principal_amount) || 0) * (this.processingFeePercent || 0) / 100 + (this.processingFeeFlat || 0);
                },
                // Rupees as the rest of the app shows them (₹44,000.00); Number() also covers a value typed as text.
                money(v) {
                    return (Number(v) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                init() {
                    this.items.forEach((_, i) => this.recalcItem(i));
                    // If a borrower was preselected (?customer_id= from the borrower
                    // profile/list), show their name in the picker on load.
                    if (this.preselected) this.selectCustomer(this.preselected, true);
                },

                searchBorrowers() {
                    const q = (this.customerSearch || '').trim();
                    clearTimeout(this.searchTimer);
                    const seq = ++this.searchSeq; // an older answer never overwrites a newer one
                    if (q.length < 2) { this.results = []; this.searchState = 'idle'; return; } // suggestions only after typing
                    this.searchState = 'loading';
                    this.searchTimer = setTimeout(async () => {
                        try {
                            const res = await fetch('{{ route('dhiran.borrowers.search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                            if (!res.ok) throw new Error(res.status);
                            const rows = await res.json();
                            if (seq !== this.searchSeq) return;
                            this.results = rows; this.searchState = 'done';
                        } catch (e) {
                            if (seq === this.searchSeq) { this.results = []; this.searchState = 'failed'; }
                        }
                    }, 250);
                },

                handleCustomerSearchInput() {
                    this.customerDropdownOpen = this.customerSearch.trim().length > 0;
                    this.searchBorrowers();
                    if (this.selectedCustomer && this.customerSearch.trim() !== (this.selectedCustomer.name || '')) {
                        this.selectedCustomer = null;
                        this.customerDisplayName = '';
                        this.loanPan = '';
                        this.loanAadhaar = '';
                    }
                },

                openNewBorrower() {
                    this.customerDropdownOpen = false;
                    this.nbError = '';
                    this.nbField = '';
                    this.nbState = 'open';
                },

                closeNewBorrower() {
                    if (this.nbState === 'open') this.nbState = 'closed'; // never mid-save: the answer would land unseen
                },

                aadhaarCandidate(customer) {
                    const id = (customer && customer.id_number ? customer.id_number : '').trim();
                    return /^[0-9Xx\- ]{4,20}$/.test(id) ? id : '';
                },

                async selectCustomer(customer, keepExistingKyc = false) {
                    this.selectedCustomer = customer;
                    this.customerDisplayName = customer.name + (customer.mobile ? ' (' + customer.mobile + ')' : '');
                    this.customerSearch = customer.name || '';
                    this.customerDropdownOpen = false;
                    this.results = []; this.searchSeq++; // drop any search still in flight
                    if (!keepExistingKyc) { this.loanPan = ''; this.loanAadhaar = ''; } // never keep the previous borrower's KYC
                    // PAN / ID are not in the page: fetch this one borrower's, then prefill as before.
                    try {
                        const res = await fetch('{{ url('/dhiran/borrowers') }}/' + encodeURIComponent(customer.id) + '/kyc', { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const kyc = await res.json();
                        if (!this.selectedCustomer || this.selectedCustomer.id !== customer.id) return; // picked someone else meanwhile
                        this.selectedCustomer.pan = kyc.pan || '';
                        this.selectedCustomer.id_number = kyc.id_number || '';
                    } catch (e) { return; }
                    if (!keepExistingKyc || !this.loanPan) this.loanPan = this.selectedCustomer.pan;
                    if (!keepExistingKyc || !this.loanAadhaar) this.loanAadhaar = this.aadhaarCandidate(this.selectedCustomer);
                },

                // Names the field a refusal is about, puts the cursor there and says so (aria-invalid).
                nbRefuse(message, field) {
                    this.nbError = message;
                    this.nbField = field;
                    const el = document.getElementById('nb-' + field);
                    if (el) el.focus();
                },

                async saveBorrower() {
                    this.nbError = '';
                    this.nbField = '';
                    if (!this.nb.first_name.trim()) { this.nbRefuse('First name is required.', 'first_name'); return; }
                    if (!/^[0-9]{10}$/.test(this.nb.mobile.trim())) { this.nbRefuse('Enter a valid 10-digit mobile number.', 'mobile'); return; }
                    if (this.nbState !== 'open') return; // one save at a time
                    this.nbState = 'saving';
                    try {
                        const res = await fetch('{{ route('dhiran.customers.store') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify(this.nb),
                        });
                        const out = await res.json().catch(() => ({}));
                        if (!res.ok || !out.ok) {
                            this.nbError = (out.errors ? Object.values(out.errors)[0][0] : out.message) || 'Could not save borrower.';
                            this.nbField = out.errors ? Object.keys(out.errors)[0] : '';
                            return;
                        }
                        const savedCustomer = {
                            id: out.customer.id,
                            name: out.customer.name,
                            mobile: out.customer.mobile || '',
                            address: out.customer.address || '',
                            pan: out.customer.pan || '',
                            id_number: out.customer.id_number || '',
                            state_code: out.customer.state_code || '',
                        };

                        this.selectCustomer(savedCustomer);
                        this.nbState = 'closed';
                        this.nb = { first_name: '', last_name: '', mobile: '', pan: '', id_number: '', state_code: '', address: '' };
                    } catch (e) {
                        this.nbError = 'Network error. Please try again.';
                    } finally {
                        if (this.nbState === 'saving') this.nbState = 'open'; // refused: back to the form, error shown
                    }
                },

                addItem() {
                    this.items.push({
                        description: '', metal_type: 'gold', value_mode: 'metal', gross_weight: 0, stone_weight: 0, purity: 22,
                        rate_per_gram_at_pledge: 0, huid: '',
                        net_metal_weight: 0, fine_weight: 0, market_value: 0, loan_value: 0
                    });
                },

                focusItem(index) {
                    document.getElementById('it-desc-' + index)?.focus();
                },

                itemErr(index) {
                    return this.itemErrors[index] || '';
                },

                // Supported purities per metal (MetalRegistry: gold = karat/24,
                // silver = millesimal/1000). Display-list only; the backend is
                // authoritative.
                purityOptions(metal) {
                    if (metal === 'silver') {
                        return [
                            { value: 999, label: '999' },
                            { value: 958, label: '958' },
                            { value: 925, label: '925' },
                            { value: 900, label: '900' },
                            { value: 800, label: '800' },
                        ];
                    }
                    return [
                        { value: 24, label: '24K' },
                        { value: 22, label: '22K' },
                        { value: 20, label: '20K' },
                        { value: 18, label: '18K' },
                        { value: 14, label: '14K' },
                    ];
                },

                // When metal changes, reset a now-invalid purity and recompute.
                onMetalChange(index) {
                    const item = this.items[index];
                    if (item.metal_type === 'other') {
                        // Other = always appraised; clear metal-only fields.
                        item.value_mode = 'appraised';
                        item.purity = '';
                    } else {
                        const valid = this.purityOptions(item.metal_type).some(o => o.value === Number(item.purity));
                        if (!valid) item.purity = '';
                    }
                    this.recalcItem(index);
                },

                removeItem(index) {
                    if (this.items.length > 1) {
                        this.items.splice(index, 1);
                    }
                },

                // An item the clerk has typed into is never dropped by a stray tap: ask first.
                confirmRemove(index) {
                    const i = this.items[index];
                    const typed = (i.description || '').trim() || i.gross_weight > 0 || i.market_value > 0;
                    if (!typed || window.confirm('Remove item ' + (index + 1) + '?')) this.removeItem(index);
                },

                recalcItem(index) {
                    let item = this.items[index];
                    const appraised = item.metal_type === 'other' || item.value_mode === 'appraised';
                    if (appraised) {
                        // Operator-entered market value is authoritative; no metal math.
                        item.net_metal_weight = 0;
                        item.fine_weight = 0;
                        item.market_value = Number(item.market_value) || 0;
                    } else {
                        item.net_metal_weight = Math.max(0, (item.gross_weight || 0) - (item.stone_weight || 0));
                        // Metal-aware fine-weight scale: gold = purity/24 (karat),
                        // silver = purity/1000 (millesimal). Mirrors MetalRegistry.
                        const divisor = item.metal_type === 'silver' ? 1000 : 24;
                        item.fine_weight = item.net_metal_weight * (item.purity || 0) / divisor;
                        let rate = item.rate_per_gram_at_pledge || this.goldRateOnDate || 0;
                        item.market_value = item.fine_weight * rate;
                    }
                    item.loan_value = item.market_value * (this.ltvPercent / 100);
                },

                prepareSubmit(e) {
                    if (this.submitting) { e.preventDefault(); return; } // one tap = one loan; the key below also guards it server-side
                    // Recalculate all items before submit
                    this.items.forEach((_, i) => this.recalcItem(i));
                    this.submitting = true;
                }
            }));
        };
        // A Turbo visit runs this script again but alpine:init fired on an earlier page: register now, so the form
        // gets this render's values (not none, nor a previous page's). The IIFE keeps the const re-runnable.
        window.Alpine ? registerDhiranCreateForm() : document.addEventListener('alpine:init', registerDhiranCreateForm);
        })();
    </script>
    @endpush
</x-dhiran-layout>
