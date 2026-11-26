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

        <form method="POST" action="{{ route('dhiran.store') }}" enctype="multipart/form-data" id="fm-form" class="fm-page dhiran-create-form" data-autosave="new-loan" data-autosave-label="loan" @submit="prepareSubmit($event)" @turbo:submit-end="if (!$event.detail.success) submitting = false" data-turbo-frame="_top">
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



            <div class="fm-layout">
            <div class="fm-main">
            {{-- 1 Customer --}}
            <section class="fm-card" id="fm-step-customer" aria-labelledby="fm-h-customer">
                <header class="fm-card-head">
                    <div>
                        <h2 class="fm-title" id="fm-h-customer">Customer</h2>
                        <p class="fm-desc">Who is pledging? Search the borrowers you have saved, or add a new one.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <input type="hidden" name="customer_id" :value="customer_id">
                    <div class="fm-borrower-row">
                    <div class="fm-field fm-combo" @click.outside="customerDropdownOpen = false">
                        <label class="fm-label" for="fm-customer-search">Borrower <x-req /></label>
                        <div class="fm-affix" :class="{ 'is-invalid': {{ $errors->has('customer_id') ? 'true' : 'false' }} && !selectedCustomer }">
                            <span class="fm-unit fm-unit--pre" aria-hidden="true"><x-icon name="search" size="16" /></span>
                            <input type="search" id="fm-customer-search" class="fm-control"
                                   placeholder="Name or mobile" autocomplete="off" autocapitalize="off" spellcheck="false"
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
                    </div>

                    <div class="fm-picked" x-show="selectedCustomer" x-cloak aria-live="polite">
                        <div class="fm-picked-head">
                            <button type="button" class="fm-avatar-btn" @click="selectedCustomer && selectedCustomer.photoUrl && viewPhoto(selectedCustomer.photoUrl, selectedCustomer.name)" :disabled="!(selectedCustomer && selectedCustomer.photoUrl)" :aria-label="selectedCustomer && selectedCustomer.photoUrl ? 'View the borrower photo full size' : 'No borrower photo'" :title="selectedCustomer && selectedCustomer.photoUrl ? 'View full size' : 'No photo yet'">
                                <span class="customer-avatar">
                                    <span x-show="!(selectedCustomer && selectedCustomer.photoUrl)" x-text="borrowerInitials"></span>
                                    <img x-show="selectedCustomer && selectedCustomer.photoUrl" :src="selectedCustomer ? selectedCustomer.photoUrl : ''" alt="" decoding="async">
                                </span>
                            </button>
                            <p class="fm-picked-name" x-text="selectedCustomer && selectedCustomer.name ? selectedCustomer.name : 'Borrower'"></p>
                        </div>
                        <dl class="fm-facts">
                            <div><dt>Mobile</dt><dd x-text="selectedCustomer && selectedCustomer.mobile ? selectedCustomer.mobile : 'Not added'"></dd></div>
                            <div><dt>State code</dt><dd x-text="selectedCustomer && selectedCustomer.state_code ? selectedCustomer.state_code : 'Not added'"></dd></div>
                            <div class="fm-wide"><dt>Address</dt><dd x-text="selectedCustomer && selectedCustomer.address ? selectedCustomer.address : 'Not added'"></dd></div>
                        </dl>
                    </div>
                    <p class="fm-note" x-show="!selectedCustomer" x-cloak>No borrower chosen yet. Their details will show here.</p>
                    <p class="fm-alert fm-alert--inline" role="status" x-show="borrowerNote" x-cloak><x-icon name="circle-alert" size="20" /><span x-text="borrowerNote"></span></p>
                </div>
            </section>

            {{-- 2 Pledged items --}}
            <section class="fm-card" id="fm-step-items" aria-labelledby="fm-h-items">
                <header class="fm-card-head">
                    <div>
                        <h2 class="fm-title" id="fm-h-items">Pledged items</h2>
                        <p class="fm-desc">One card for each piece the customer leaves with you.</p>
                        @php($itemShop = auth()->user()->shop)
                        @if(($itemCap = $itemShop->itemLimit()) > 0 && ($itemUsed = $itemShop->pledgedItemCount()) * 100 >= $itemCap * 80)
                            <p class="fm-desc">Pledged items: {{ $itemUsed }} of {{ $itemCap }} (this access term)</p>
                        @endif
                    </div>
                    <button type="button" class="btn btn-secondary fm-add-item" data-autosave-add-row aria-label="Add another item" title="Add another item" @click="addItem(); $nextTick(() => focusItem(items.length - 1))">
                        <x-icon name="plus" size="16" /> <span class="fm-add-text">Add another item</span>
                    </button>
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

                            {{-- Rows of right-sized fields (.fm-w-*): description + HUID, metal / valuation / purity / rate, weights with the worked-out net and fine, then value and photo. --}}
                            <div class="fm-row fm-irow fm-irow--ident">
                                <div class="fm-field fm-span fm-w-xl">
                                    <label class="fm-label" :for="'it-desc-' + index">Description <x-req /></label>
                                    <input type="text" :id="'it-desc-' + index" :name="'items['+index+'][description]'" x-model="item.description" data-item-desc
                                           placeholder="e.g. Gold chain, silver anklet" class="fm-control" required aria-required="true" autocomplete="off">
                                </div>
                                <div class="fm-field fm-span fm-w-lg">
                                    <label class="fm-label" :for="'it-huid-' + index">HUID</label>
                                    <input type="text" :id="'it-huid-' + index" :name="'items['+index+'][huid]'" x-model="item.huid"
                                           placeholder="6-character ID" class="fm-control" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false">
                                </div>
                            </div>

                            <div class="fm-row fm-irow">
                                <div class="fm-field fm-w-md">
                                    <label class="fm-label" :for="'it-metal-' + index">Metal</label>
                                    <select :id="'it-metal-' + index" :name="'items['+index+'][metal_type]'" x-model="item.metal_type" class="fm-control" @change="onMetalChange(index)">
                                        <option value="gold">Gold</option>
                                        <option value="silver">Silver</option>
                                        <option value="other">Other (diamond, platinum, watch…)</option>
                                    </select>
                                </div>
                                {{-- Metal-mode fields (gold/silver, auto valuation) --}}
                                <template x-if="item.metal_type !== 'other'">
                                    <div class="fm-field fm-w-sm">
                                        <label class="fm-label" :for="'it-purity-' + index">Purity</label>
                                        {{-- Standard purities (from MetalRegistry) or Other = type any value. The posted purity is the hidden field: gold = karat, silver = fineness. --}}
                                        <select :id="'it-purity-' + index" data-autosave-include class="fm-control" @change="pickPurity(index, $event.target.value)">
                                            <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
                                                {{-- :selected — the options draw after the value is set, so a plain value showed the first option. --}}
                                                <option :value="String(opt.value)" x-text="opt.label" :selected="String(opt.value) === item.purityPick"></option>
                                            </template>
                                            <option value="other" :selected="item.purityPick === 'other'">Other</option>
                                        </select>
                                        <input type="hidden" :name="'items['+index+'][purity]'" :value="item.purity">
                                    </div>
                                </template>
                                {{-- Custom purity sits in its own grid field so picking "Other" never pushes sibling controls out of alignment. --}}
                                <div class="fm-field fm-w-sm" x-show="item.metal_type !== 'other' && item.purityPick === 'other'" x-cloak>
                                    <label class="fm-label" :for="'it-purity-custom-' + index" x-text="item.metal_type === 'silver' ? 'Custom fineness' : 'Custom karat'">Custom purity</label>
                                    <input type="number" inputmode="decimal" step="0.01" min="0" data-autosave-include :id="'it-purity-custom-' + index" x-model.number="item.purity"
                                           class="fm-control" :placeholder="item.metal_type === 'silver' ? 'e.g. 925.5' : 'e.g. 22.5'" :aria-label="'Purity, ' + (item.metal_type === 'silver' ? 'fineness' : 'karat')" @input="recalcItem(index)" @focus="$event.target.select()">
                                </div>
                                {{-- Valuation: metal-melt (auto from purity × rate) OR appraised (operator enters value). 'Other' is always appraised. --}}
                                <div class="fm-field fm-span fm-w-lg" x-show="item.metal_type !== 'other'">
                                    <label class="fm-label" :for="'it-mode-' + index">Valuation</label>
                                    <select :id="'it-mode-' + index" :name="'items['+index+'][value_mode]'" x-model="item.value_mode" class="fm-control" @change="recalcItem(index)">
                                        <option value="metal">Auto (metal value)</option>
                                        <option value="appraised">Appraised (enter value)</option>
                                    </select>
                                </div>

                                <template x-if="item.metal_type !== 'other' && item.value_mode !== 'appraised'">
                                    <div class="fm-field fm-w-md">
                                        <label class="fm-label" :for="'it-rate-' + index" x-text="item.metal_type === 'silver' ? 'Silver rate per gram (999)' : 'Gold rate per gram (24K)'">Gold rate per gram (24K)</label>
                                        <div class="fm-affix">
                                            <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                            <input type="number" inputmode="decimal" step="0.01" min="0" :id="'it-rate-' + index" :name="'items['+index+'][rate_per_gram_at_pledge]'" x-model.number="item.rate_per_gram_at_pledge"
                                                   placeholder="0.00" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                        </div>
                                    </div>
                                </template>

                                <div class="fm-field fm-w-md" x-show="item.metal_type === 'other' || item.value_mode === 'appraised'">
                                    <label class="fm-label" :for="'it-value-' + index">Appraised value</label>
                                    <div class="fm-affix">
                                        <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                        <input type="number" inputmode="decimal" step="0.01" min="0" :id="'it-value-' + index" :name="'items['+index+'][market_value]'"
                                               :disabled="!(item.metal_type === 'other' || item.value_mode === 'appraised')"
                                               x-model.number="item.market_value" placeholder="0.00" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                    </div>
                                </div>
                            </div>
                            <p class="fm-note fm-hint" aria-live="polite" x-text="purityNote(item)"></p>

                            <div class="fm-row fm-irow">
                                <div class="fm-field fm-w-sm">
                                    <label class="fm-label" :for="'it-gross-' + index">Gross weight</label>
                                    <div class="fm-affix">
                                        <input type="number" inputmode="decimal" step="0.001" min="0" :id="'it-gross-' + index" :name="'items['+index+'][gross_weight]'" x-model.number="item.gross_weight"
                                               placeholder="0.000" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                        <span class="fm-unit fm-unit--suf">g</span>
                                    </div>
                                </div>
                                <div class="fm-field fm-w-sm">
                                    <label class="fm-label" :for="'it-stone-' + index">Stone / other weight</label>
                                    <div class="fm-affix">
                                        <input type="number" inputmode="decimal" step="0.001" min="0" :id="'it-stone-' + index" :name="'items['+index+'][stone_weight]'" x-model.number="item.stone_weight"
                                               placeholder="0.000" class="fm-control" @input="recalcItem(index)" @focus="$event.target.select()">
                                        <span class="fm-unit fm-unit--suf">g</span>
                                    </div>
                                </div>
                                {{-- Net = gross - stone; fine (pure metal) = net x purity (gold / silver only). Worked out as you type, same rule as the server, whether the value is melt or appraised. --}}
                                <dl class="fm-readout fm-readout--stat fm-span" aria-live="polite">
                                    <div><dt>Net weight</dt><dd x-text="item.net_metal_weight.toFixed(3) + ' g'"></dd></div>
                                    <div x-show="item.metal_type !== 'other'"><dt>Fine (pure metal) weight</dt><dd x-text="item.fine_weight.toFixed(3) + ' g'"></dd></div>
                                </dl>
                            </div>
                            <p class="fm-error" role="alert" x-show="stoneTooHeavy(item)" x-cloak><x-icon name="circle-alert" size="16" /><span>Stone weight must be less than the gross weight.</span></p>

                            {{-- What the item is worth, worked out as you type; the photo of this item (saved with the loan as item_photo evidence, so activation needs no second trip) sits at the end of the row. --}}
                            <div class="fm-row fm-irow fm-item-foot">
                                <dl class="fm-readout fm-readout--stat fm-span" aria-live="polite">
                                    <div><dt>Market value</dt><dd x-text="'{{ $sym }}' + money(item.market_value)"></dd></div>
                                    <div><dt>Loan value</dt><dd x-text="'{{ $sym }}' + money(item.loan_value)"></dd></div>
                                </dl>
                                <div class="fm-field fm-span fm-foot-end">
                                    <span class="fm-label">Photo of this item</span>
                                    <div class="fm-photo-row">
                                        <button type="button" class="fm-thumb-btn" x-show="item.photoUrl" x-cloak @click="viewPhoto(item.photoUrl, 'Item ' + (index + 1))" :aria-label="'View the photo of item ' + (index + 1) + ' full size'" title="View full size">
                                            <img class="fm-photo-thumb" :src="item.photoUrl" alt="">
                                        </button>
                                        <button type="button" class="btn btn-secondary fm-photo-act" @click="document.getElementById('it-photo-pick-' + index).click()" :title="item.photoFile ? 'Change photo (upload a file)' : 'Upload a photo'" :aria-label="(item.photoFile ? 'Change photo of item ' : 'Upload a photo of item ') + (index + 1)">
                                            <x-icon name="upload" size="18" />
                                        </button>
                                        <button type="button" class="btn btn-secondary fm-photo-act" @click="openCamera({ kind: 'item', index })" title="Take a photo with the camera" :aria-label="'Take a photo of item ' + (index + 1) + ' with the camera'">
                                            <x-icon name="camera" size="18" />
                                        </button>
                                        <button type="button" class="btn btn-secondary fm-photo-act" x-show="item.photoFile" x-cloak @click="viewPhoto(item.photoUrl, 'Item ' + (index + 1))" :aria-label="'View the photo of item ' + (index + 1) + ' full size'" title="View full size">
                                            <x-icon name="eye" size="18" />
                                        </button>
                                        <button type="button" class="btn btn-secondary fm-photo-act" x-show="item.photoFile" x-cloak @click="clearItemPhoto(index)" :aria-label="'Remove photo of item ' + (index + 1)" title="Remove photo">
                                            <x-icon name="trash-2" size="18" />
                                        </button>
                                    </div>
                                    <input type="file" hidden :id="'it-photo-pick-' + index" accept="image/jpeg,image/png" @change="pickItemPhoto(index, $event)">
                                    <p class="fm-hint" role="status" x-show="item.photoBusy" x-cloak>Preparing photo…</p>
                                    <p class="fm-error" role="alert" x-show="item.photoError" x-cloak><x-icon name="circle-alert" size="16" /><span x-text="item.photoError"></span></p>
                                    {{-- The file that is posted: filled from the item's own photo at submit, so removing or reordering items can never mismatch a photo. --}}
                                    <input type="file" class="fm-sr" tabindex="-1" aria-hidden="true" :id="'it-photo-submit-' + index" :name="'items['+index+'][photo]'">
                                </div>
                            </div>
                            <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                            {{-- Hidden carriers so server always gets net/fine even when the weights are hidden (0 for appraised/other). --}}
                            <input type="hidden" :name="'items['+index+'][net_metal_weight]'" :value="item.net_metal_weight">
                            <input type="hidden" :name="'items['+index+'][fine_weight]'" :value="item.fine_weight">
                        </fieldset>
                    </template>
                </div>
            </section>

            {{-- 3 Loan terms --}}
            <section class="fm-card" id="fm-step-terms" aria-labelledby="fm-h-terms">
                <header class="fm-card-head">
                    <div>
                        <h2 class="fm-title" id="fm-h-terms">Loan terms</h2>
                        <p class="fm-desc">Amount, interest and period.</p>
                    </div>
                    @if($settings->advance_interest_enabled)
                    {{-- This loan only: switching it never changes the shop's Loan settings. --}}
                    <label class="ui-switch-row fm-head-switch" for="advance_on">
                        <span class="ui-switch-text"><span class="ui-switch-label">Interest paid in advance</span></span>
                        <span class="ui-switch">
                            <input type="checkbox" role="switch" id="advance_on" data-autosave-include class="ui-switch-input" x-model="advance_on">
                            <span class="ui-switch-track" aria-hidden="true"><span class="ui-switch-thumb"></span></span>
                        </span>
                    </label>
                    @endif
                </header>
                <div class="fm-card-body">
                    <div class="fm-field">
                        <label for="principal_amount" class="fm-label">Principal amount <x-req /></label>
                        <div class="fm-affix fm-affix--lead fm-w-lg" :class="{ 'is-invalid': principalProblem }">
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
                            {{-- What can be lent: the limit, what the items are worth, what is still free, and how much of the limit this principal uses. --}}
                            <div class="fm-limitbox" :class="{ 'is-over': maxPrincipal > 0 && (Number(principal_amount) || 0) > maxPrincipal }">
                                <dl class="fm-limitbox-figs">
                                    <div class="fm-limitbox-lead"><dt x-text="'Max loan at ' + ltvPercent + '% LTV'">Max loan</dt><dd x-text="'{{ $sym }}' + money(maxPrincipal)"></dd></div>
                                    <div><dt>Items' market value</dt><dd x-text="'{{ $sym }}' + money(totalMarketValue)"></dd></div>
                                    <div>
                                        <dt x-text="(Number(principal_amount) || 0) > maxPrincipal && maxPrincipal > 0 ? 'Over the limit by' : 'Still available'">Still available</dt>
                                        <dd x-text="'{{ $sym }}' + money(Math.abs(maxPrincipal - (Number(principal_amount) || 0)))"></dd>
                                    </div>
                                </dl>
                                <div class="fm-meter" role="meter" aria-label="Principal against the maximum loan" aria-valuemin="0" aria-valuemax="100"
                                     :aria-valuenow="maxPrincipal > 0 ? Math.min(100, Math.round((Number(principal_amount) || 0) / maxPrincipal * 100)) : 0"
                                     :aria-valuetext="maxPrincipal > 0 ? Math.round((Number(principal_amount) || 0) / maxPrincipal * 100) + '% of the maximum loan used' : 'No items added yet'">
                                    <span :style="'--used:' + (maxPrincipal > 0 ? Math.min(1, (Number(principal_amount) || 0) / maxPrincipal) : 0)"></span>
                                </div>
                            </div>
                    </div>
                    <div class="fm-row">
                        <div class="fm-field fm-w-unit">
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
                        <div class="fm-field fm-w-unit">
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
                        <div class="fm-field fm-span fm-w-unit">
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
                        <div class="fm-field fm-w-sm">
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
                        @if($settings->advance_interest_enabled)
                        <div class="fm-field fm-span fm-w-lg" x-show="advance_on" x-cloak>
                            <label for="advance_interest_months" class="fm-label">Months taken now</label>
                            {{-- Off posts a single 0 (a paid-afterwards loan); on posts the chosen months and the next installment falls due each month after. --}}
                            <input type="hidden" name="advance_interest_months" value="0" :disabled="advance_on">
                            <select name="advance_interest_months" id="advance_interest_months" x-model.number="advance_months_choice" :disabled="!advance_on" class="fm-control"
                                    @error('advance_interest_months') aria-invalid="true" aria-describedby="err-advance_interest_months" @enderror>
                                @foreach([1 => 'First month now', 2 => 'First 2 months now', 3 => 'First 3 months now'] as $m => $label)
                                    <option value="{{ $m }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('advance_interest_months')
                                <p class="fm-error" id="err-advance_interest_months" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>
                            @enderror
                        </div>
                        @endif
                        <div class="fm-field fm-span fm-w-lg">
                            <label for="gold_rate_on_date" class="fm-label">Gold rate today (24K)</label>
                            <div class="fm-affix">
                                <span class="fm-unit fm-unit--pre">{{ $sym }}</span>
                                <input type="number" inputmode="decimal" step="0.01" name="gold_rate_on_date" id="gold_rate_on_date"
                                       x-model.number="goldRateOnDate" @input="applyGoldRate()"
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
                        <div class="fm-field fm-span fm-w-lg" x-cloak x-show="items.some(i => i.metal_type === 'silver' && i.value_mode !== 'appraised')">
                            <label for="silver_rate_on_date" class="fm-label">Silver rate today (pure, per gram)</label>
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

                </div>
            </section>

            {{-- 4 KYC & notes --}}
            <section class="fm-card" id="fm-step-kyc" aria-labelledby="fm-h-kyc">
                <header class="fm-card-head">
                    <div>
                        <h2 class="fm-title" id="fm-h-kyc">KYC &amp; notes</h2>
                        <p class="fm-desc">PAN and ID number come from the borrower's saved profile. Add the ID proof and PAN card photos here.</p>
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
                    {{-- The borrower's ID proof and PAN card, saved to the borrower: upload a file or take a live photo. --}}
                    <div class="fm-row fm-slots">
                        <template x-for="d in docDefs" :key="d.key">
                            <div class="fm-field fm-span fm-slot">
                                <span class="fm-label" x-text="d.label"></span>
                                <div class="fm-photo-row">
                                    <button type="button" class="fm-thumb-btn" x-show="docs[d.key].url" x-cloak @click="viewPhoto(docs[d.key].url, d.label)" :aria-label="'View ' + d.label + ' full size'" title="View full size">
                                        <img class="fm-photo-thumb" :src="docs[d.key].url" alt="">
                                    </button>
                                    <span class="fm-doc-chip" x-show="docs[d.key].pdf" x-cloak><x-icon name="file-text" size="18" /><span x-text="docs[d.key].name"></span></span>
                                    <button type="button" class="btn btn-secondary fm-photo-act" @click="document.getElementById('doc-pick-' + d.key).click()" :title="'Upload ' + d.label" :aria-label="'Upload ' + d.label">
                                        <x-icon name="upload" size="18" />
                                    </button>
                                    <button type="button" class="btn btn-secondary fm-photo-act" @click="openCamera({ kind: 'doc', key: d.key })" :title="'Take a photo of ' + d.label" :aria-label="'Take a photo of ' + d.label + ' with the camera'">
                                        <x-icon name="camera" size="18" />
                                    </button>
                                    <button type="button" class="btn btn-secondary fm-photo-act" x-show="docs[d.key].url" x-cloak @click="viewPhoto(docs[d.key].url, d.label)" :aria-label="'View ' + d.label + ' full size'" title="View full size">
                                        <x-icon name="eye" size="18" />
                                    </button>
                                    <button type="button" class="btn btn-secondary fm-photo-act" x-show="docs[d.key].file" x-cloak @click="clearDoc(d.key)" :aria-label="'Remove ' + d.label" title="Remove">
                                        <x-icon name="trash-2" size="18" />
                                    </button>
                                </div>
                                <input type="file" hidden :id="'doc-pick-' + d.key" accept="image/jpeg,image/png,application/pdf" @change="pickDoc(d.key, $event)">
                                <p class="fm-hint" role="status" x-show="docs[d.key].busy" x-cloak>Preparing photo…</p>
                                <p class="fm-error" role="alert" x-show="docs[d.key].error" x-cloak><x-icon name="circle-alert" size="16" /><span x-text="docs[d.key].error"></span></p>
                                {{-- The file that is posted: filled from the slot at submit. --}}
                                <input type="file" class="fm-sr" tabindex="-1" aria-hidden="true" :id="'doc-submit-' + d.key" :name="d.key">
                            </div>
                        </template>
                    </div>
                    <p class="fm-note" x-show="selectedCustomer && selectedCustomer.hasIdProof" x-cloak>This borrower already has an ID proof saved. Add one here only if it has changed.</p>
                    <p class="fm-note">JPG, PNG or PDF, up to 8 MB each. Saved to the borrower's profile.</p>
                    @error('id_proof_front')<p class="fm-error" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>@enderror
                    @error('id_proof_back')<p class="fm-error" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>@enderror
                    @error('pan_card')<p class="fm-error" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>@enderror
                    <div class="fm-field">
                        <label for="notes" class="fm-label">Notes</label>
                        <textarea name="notes" id="notes" rows="3" placeholder="Anything else to remember about this loan" class="fm-control">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </section>

            </div>
            <aside class="fm-aside" aria-labelledby="fm-h-summary">
                <div class="fm-aside-card">
                    <h2 class="fm-aside-title" id="fm-h-summary">Loan summary</h2>
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
                        <div x-show="advanceInterest > 0" x-cloak>
                            <dt>Next installment due</dt>
                            <dd x-text="nextInstallmentLabel"></dd>
                        </div>
                        @endif
                    </dl>
                    <div class="fm-aside-acts">
                        <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting.toString()">
                            <x-icon name="save" size="16" /> <span x-text="submitting ? 'Creating…' : 'Create loan'">Create loan</span>
                        </button>
                    </div>
                </div>
            </aside>
            </div>

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

        {{-- Live camera for an item photo. Esc or Close stops the camera; the shot becomes the item's photo straight away. --}}
        <dialog class="fm-dialog fm-cam" x-ref="camDialog" aria-labelledby="fm-cam-title" @cancel.prevent="closeCamera()" @click.self="closeCamera()">
            <div class="fm-dialog-card">
                <div class="fm-dialog-head">
                    <div>
                        <h2 id="fm-cam-title" x-text="camTitle">Take photo</h2>
                        <p>Hold it in view, then take the photo.</p>
                    </div>
                    <button type="button" class="btn btn-secondary fm-dialog-x" @click="closeCamera()" title="Close"><x-icon name="x" size="18" /><span class="fm-sr">Close</span></button>
                </div>
                <div class="fm-dialog-body">
                    <p class="fm-alert fm-alert--inline" role="alert" x-show="camError" x-cloak><x-icon name="circle-alert" size="20" /><span x-text="camError"></span></p>
                    <video class="fm-cam-video" x-ref="camVideo" playsinline muted autoplay aria-label="Live camera preview"></video>
                </div>
                <div class="fm-dialog-foot">
                    <button type="button" class="btn btn-secondary" x-show="camCanSwitch" x-cloak @click="switchCamera()"><x-icon name="switch-camera" size="16" /> Switch camera</button>
                    <button type="button" class="btn btn-secondary" @click="closeCamera()">Cancel</button>
                    <button type="button" class="btn btn-primary" @click="captureItemPhoto()"><x-icon name="camera" size="16" /> Take photo</button>
                </div>
            </div>
        </dialog>

        {{-- Full-size view of any photo on this form (item, ID proof, PAN card, borrower). Click outside, Esc or Close to leave. --}}
        <dialog class="fm-view" x-ref="photoView" aria-label="Photo, full size" @cancel.prevent="closePhotoView()" @click="closePhotoView()">
            <figure class="fm-view-card" @click.stop>
                <button type="button" class="btn btn-secondary fm-view-x" @click="closePhotoView()" title="Close"><x-icon name="x" size="18" /><span class="fm-sr">Close</span></button>
                <img :src="viewUrl" :alt="viewTitle">
                <figcaption x-text="viewTitle"></figcaption>
            </figure>
        </dialog>

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
                    @can('dhiran.create')
                        <x-customer-photo mode="form" />
                    @endcan
                    <div class="fm-row">
                        <div class="fm-field fm-w-lg">
                            <label class="fm-label" for="nb-first_name">First name <x-req /></label>
                            <input type="text" id="nb-first_name" class="fm-control" x-model="nb.first_name" aria-required="true" autocomplete="off" maxlength="255" :aria-invalid="nbField === 'first_name' ? 'true' : null" autofocus>
                        </div>
                        <div class="fm-field fm-w-lg">
                            <label class="fm-label" for="nb-last_name">Last name</label>
                            <input type="text" id="nb-last_name" class="fm-control" x-model="nb.last_name" autocomplete="off" maxlength="255">
                        </div>
                        <div class="fm-field fm-w-md">
                            <label class="fm-label" for="nb-mobile">Mobile number <x-req /></label>
                            <input type="tel" id="nb-mobile" class="fm-control" inputmode="numeric" placeholder="10 digits" maxlength="10" autocomplete="off" aria-required="true" x-model="nb.mobile" :aria-invalid="nbField === 'mobile' ? 'true' : null">
                        </div>
                        <div class="fm-field fm-w-md">
                            <label class="fm-label" for="nb-pan">PAN</label>
                            <input type="text" id="nb-pan" class="fm-control" placeholder="ABCDE1234F" maxlength="10" autocomplete="off" autocapitalize="characters" spellcheck="false" x-model="nb.pan" @input="nb.pan = nb.pan.toUpperCase()">
                        </div>
                        <div class="fm-field fm-w-lg">
                            <label class="fm-label" for="nb-id_number">ID number</label>
                            <input type="text" id="nb-id_number" class="fm-control" autocomplete="off" x-model="nb.id_number">
                        </div>
                        <div class="fm-field fm-w-xs">
                            <label class="fm-label" for="nb-state_code">State code</label>
                            <input type="text" id="nb-state_code" class="fm-control" autocomplete="off" x-model="nb.state_code">
                        </div>
                        <div class="fm-field fm-span">
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
                selectedCustomer: null,
                // Add Borrower dialog: one state, 'closed' -> 'open' -> 'saving' -> 'closed' (saved) or 'open' (refused).
                nbState: 'closed',
                borrowerNote: '',
                // ID proof and PAN card: each slot holds its file (chosen or taken with the camera) until the form is sent.
                docDefs: [{ key: 'id_proof_front', label: 'ID proof, front' }, { key: 'id_proof_back', label: 'ID proof, back' }, { key: 'pan_card', label: 'PAN card' }],
                docs: {
                    id_proof_front: { file: null, url: '', name: '', pdf: false, error: '', busy: false },
                    id_proof_back: { file: null, url: '', name: '', pdf: false, error: '', busy: false },
                    pan_card: { file: null, url: '', name: '', pdf: false, error: '', busy: false },
                },
                pickDoc(key, ev) {
                    const f = ev.target.files && ev.target.files[0];
                    ev.target.value = '';
                    this.setDoc(key, f);
                },
                setDoc(key, f) {
                    const d = this.docs[key];
                    if (!f || !d) return Promise.resolve();
                    const pdf = f.type === 'application/pdf';
                    if (!pdf && !/^image\/(jpeg|png)$/.test(f.type)) { d.error = 'Choose a JPG, PNG or PDF file.'; return Promise.resolve(); }
                    if (f.size > (pdf ? 8 : 40) * 1024 * 1024) { d.error = 'That file is over 8 MB. Choose a smaller one.'; return Promise.resolve(); }
                    d.busy = !pdf; d.error = '';
                    const ready = pdf ? Promise.resolve(f) : window.loadPhotoTools().then((t) => t.resizeImage(f, { maxBytes: 8 * 1024 * 1024 }), () => f);
                    return ready.then((g) => {
                        d.busy = false;
                        if (g.size > 8 * 1024 * 1024) { d.error = 'That file is over 8 MB. Choose a smaller one.'; return; }
                        if (d.url) URL.revokeObjectURL(d.url);
                        d.file = g; d.name = g.name; d.pdf = pdf; d.url = pdf ? '' : URL.createObjectURL(g); d.error = '';
                    });
                },
                clearDoc(key) {
                    const d = this.docs[key];
                    if (d.url) URL.revokeObjectURL(d.url);
                    d.file = null; d.url = ''; d.name = ''; d.pdf = false; d.error = '';
                },
                // One full-size viewer for every photo on the form.
                viewUrl: '', viewTitle: '',
                viewPhoto(url, title) {
                    if (!url) return;
                    this.viewUrl = url; this.viewTitle = title || '';
                    this.$refs.photoView.showModal();
                },
                closePhotoView() {
                    if (this.$refs.photoView?.open) this.$refs.photoView.close();
                },
                get borrowerInitials() {
                    const n = (this.selectedCustomer && this.selectedCustomer.name ? this.selectedCustomer.name : '').trim().split(/\s+/).filter(Boolean);
                    return ((n[0] || '')[0] || '') + ((n[1] || '')[0] || '') || 'CU';
                },
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
                lastGoldRate: {{ old('gold_rate_on_date', 0) }},
                principal_amount: {{ old('principal_amount', 0) }},
                interest_rate_monthly: {{ old('interest_rate_monthly', $defaults['interest_rate_monthly'] ?? 1.5) }},
                interest_type: '{{ old('interest_type', $defaults['interest_type'] ?? 'flat') }}',
                // Per loan: the header switch (starts on when the shop allows it, as before) and the months chosen. Off means paid afterwards.
                advance_on: {{ $settings->advance_interest_enabled && (old('advance_interest_months') === null || (int) old('advance_interest_months') > 0) ? 'true' : 'false' }},
                advance_months_choice: {{ max(1, min(3, (int) (old('advance_interest_months') ?: $settings->advance_interest_default_months))) }},
                get advanceMonths() { return this.advance_on ? (Number(this.advance_months_choice) || 0) : 0; },
                tenure_months: {{ old('tenure_months', $defaults['tenure_months'] ?? 6) }},
                penalty_rate_monthly: {{ old('penalty_rate_monthly', $defaults['penalty_rate_monthly'] ?? 2) }},
                processingFeePercent: {{ $defaults['processing_fee_percent'] ?? 0 }},
                processingFeeFlat: {{ $defaults['processing_fee_flat'] ?? 0 }},
                ltvPercent: {{ $defaults['ltv_percent'] ?? 75 }},
                highValueLtv: {{ $defaults['high_value_ltv'] ?? 75 }},
                highValueThreshold: {{ $defaults['high_value_threshold'] ?? 250000 }},
                minLoan: {{ $defaults['min_loan'] ?? 0 }},
                maxLoan: {{ $defaults['max_loan'] ?? 0 }},

                // Standard purities per metal with the server's own multiplier (MetalRegistry::purityPresetTable).
                purityTable: {{ Illuminate\Support\Js::from(\App\Services\MetalRegistry::purityPresetTable()) }},

                // After a refused save, the items come back as typed (init() recalculates their values).
                items: {{ Illuminate\Support\Js::from(collect(old('items') ?: [[]])->values()->map(fn ($i) => [
                    'description' => (string) ($i['description'] ?? ''),
                    'metal_type' => (string) ($i['metal_type'] ?? 'gold'),
                    'value_mode' => (string) ($i['value_mode'] ?? 'metal'),
                    'gross_weight' => (float) ($i['gross_weight'] ?? 0),
                    'stone_weight' => (float) ($i['stone_weight'] ?? 0),
                    'purity' => ($i['purity'] ?? '') === '' ? ($i ? '' : 22) : (float) $i['purity'], 'purityPick' => '', // the pick is worked out in init()
                    'rate_per_gram_at_pledge' => (float) ($i['rate_per_gram_at_pledge'] ?? 0),
                    'huid' => (string) ($i['huid'] ?? ''),
                    'net_metal_weight' => 0, 'fine_weight' => 0,
                    'market_value' => (float) ($i['market_value'] ?? 0), 'loan_value' => 0,
                    'photoFile' => null, 'photoUrl' => '', 'photoError' => '', 'photoBusy' => false, // a chosen photo cannot be refilled after a refusal: choose again
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
                // Advance interest counts the real days of the first N months from today (the server's date, so both agree):
                // principal x rate / 100 / 30 per day, to the paisa.
                serverToday: '{{ today()->toDateString() }}',
                monthsFromToday(k) {
                    const [y, m, d] = this.serverToday.split('-').map(Number);
                    const first = new Date(Date.UTC(y, m - 1 + k, 1));
                    const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
                    return new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth(), Math.min(d, last)));
                },
                get advanceDays() {
                    const n = this.advanceMonths;
                    if (!n) return 0;
                    const [y, m, d] = this.serverToday.split('-').map(Number);
                    return Math.round((this.monthsFromToday(n) - new Date(Date.UTC(y, m - 1, d))) / 86400000);
                },
                get advanceInterest() {
                    return Math.round((Number(this.principal_amount) || 0) * (Number(this.interest_rate_monthly) || 0) * this.advanceDays / 30) / 100;
                },
                get nextInstallmentLabel() {
                    const n = this.advanceMonths;
                    return n ? this.monthsFromToday(n).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }) : '';
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
                    this.items.forEach((it, i) => {
                        it.purityPick = this.purityPickFor(it);
                        this.recalcItem(i);
                    });
                    // If a borrower was preselected (?customer_id= from the borrower
                    // profile/list), show their name in the picker on load.
                    if (this.preselected) this.selectCustomer(this.preselected, true);
                },

                // 300 ms after typing stops, 2+ characters, the request still in flight is cancelled, repeated names come from the session cache.
                borrowerSearch: null,
                searchBorrowers() {
                    this.borrowerSearch ??= window.createLiveSearch({
                        url: (q) => '{{ route('dhiran.borrowers.search') }}?q=' + encodeURIComponent(q),
                        onPending: () => { this.searchState = 'loading'; },
                        onIdle: () => { this.results = []; this.searchState = 'idle'; }, // suggestions only after typing
                        onResult: (q, rows) => { this.results = rows; this.searchState = 'done'; },
                        onError: () => { this.results = []; this.searchState = 'failed'; },
                    });
                    this.borrowerSearch.query(this.customerSearch);
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

                // The dialog is reused for the next borrower: clear the photo the last one chose.
                resetBorrowerPhoto() {
                    const root = this.$refs.nbDialog.querySelector('[data-customer-photo]');
                    if (!root) return;
                    const file = root.querySelector('[data-photo-form-file]');
                    if (file) file.value = '';
                    root.querySelectorAll('[data-photo-image]').forEach(img => { img.hidden = true; img.removeAttribute('src'); });
                    root.querySelectorAll('[data-photo-initials]').forEach(el => { el.hidden = false; });
                    root.querySelectorAll('[data-photo-action="change"], [data-photo-action="remove"]').forEach(b => { b.hidden = true; });
                    const status = root.querySelector('[data-photo-status]');
                    if (status) status.textContent = '';
                },

                closeNewBorrower() {
                    if (this.nbState === 'open') this.nbState = 'closed'; // never mid-save: the answer would land unseen
                },

                aadhaarCandidate(customer) {
                    const id = (customer && customer.id_number ? customer.id_number : '').trim();
                    return /^[0-9Xx\- ]{4,20}$/.test(id) ? id : '';
                },

                async selectCustomer(customer, keepExistingKyc = false) {
                    customer.photoUrl = customer.photo_url || customer.photoUrl || '';
                    this.selectedCustomer = customer;
                    this.customerDisplayName = customer.name + (customer.mobile ? ' (' + customer.mobile + ')' : '');
                    this.customerSearch = customer.name || '';
                    this.customerDropdownOpen = false;
                    this.results = []; this.borrowerSearch?.cancel(); // drop any search still in flight
                    if (!keepExistingKyc) { this.loanPan = ''; this.loanAadhaar = ''; } // never keep the previous borrower's KYC
                    // PAN / ID are not in the page: fetch this one borrower's, then prefill as before.
                    try {
                        const res = await fetch('{{ url('/dhiran/borrowers') }}/' + encodeURIComponent(customer.id) + '/kyc', { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const kyc = await res.json();
                        if (!this.selectedCustomer || this.selectedCustomer.id !== customer.id) return; // picked someone else meanwhile
                        this.selectedCustomer.pan = kyc.pan || '';
                        this.selectedCustomer.id_number = kyc.id_number || '';
                        this.selectedCustomer.hasIdProof = !!kyc.has_id_proof;
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
                        // multipart, so the optional photo (held by the photo component in the dialog) goes with the borrower
                        const fd = new FormData();
                        Object.entries(this.nb).forEach(([k, v]) => fd.append(k, v ?? ''));
                        const photo = this.$refs.nbDialog.querySelector('[data-photo-form-file]')?.files?.[0];
                        if (photo) fd.append('customer_photo', photo);
                        const res = await fetch('{{ route('dhiran.customers.store') }}', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: fd,
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
                            photoUrl: out.customer.photo_url || '',
                        };

                        this.selectCustomer(savedCustomer);
                        this.borrowerNote = out.photo_error ? 'Borrower saved, but the photo was not: ' + out.photo_error + ' Add it from the borrower profile.' : '';
                        this.nbState = 'closed';
                        this.nb = { first_name: '', last_name: '', mobile: '', pan: '', id_number: '', state_code: '', address: '' };
                        this.resetBorrowerPhoto();
                    } catch (e) {
                        this.nbError = 'Network error. Please try again.';
                    } finally {
                        if (this.nbState === 'saving') this.nbState = 'open'; // refused: back to the form, error shown
                    }
                },

                addItem() {
                    this.items.push({
                        description: '', metal_type: 'gold', value_mode: 'metal', gross_weight: 0, stone_weight: 0, purity: 22, purityPick: '22',
                        rate_per_gram_at_pledge: this.goldRateOnDate || 0, huid: '',
                        net_metal_weight: 0, fine_weight: 0, market_value: 0, loan_value: 0,
                        photoFile: null, photoUrl: '', photoError: '', photoBusy: false
                    });
                },

                // An item's photo is held on the item itself (not in a file input), so it follows the item when others are removed.
                pickItemPhoto(index, ev) {
                    const f = ev.target.files && ev.target.files[0];
                    ev.target.value = '';
                    this.setItemPhoto(index, f);
                },
                setItemPhoto(index, f) {
                    const item = this.items[index];
                    if (!f || !item) return Promise.resolve();
                    if (!/^image\/(jpeg|png)$/.test(f.type)) { item.photoError = 'Choose a JPG or PNG photo.'; return Promise.resolve(); }
                    if (f.size > 40 * 1024 * 1024) { item.photoError = 'That photo is over 8 MB. Choose a smaller one.'; return Promise.resolve(); }
                    item.photoBusy = true; item.photoError = '';
                    // shrink to about 2400 px in the browser first (the server re-encodes anyway); the 8 MB limit applies to what is sent
                    return window.loadPhotoTools().then((t) => t.resizeImage(f, { maxBytes: 8 * 1024 * 1024 }), () => f).then((g) => {
                        item.photoBusy = false;
                        if (this.items[index] !== item) return;
                        if (g.size > 8 * 1024 * 1024) { item.photoError = 'That photo is over 8 MB. Choose a smaller one.'; return; }
                        if (item.photoUrl) URL.revokeObjectURL(item.photoUrl);
                        item.photoFile = g; item.photoUrl = URL.createObjectURL(g);
                    });
                },
                // Live camera for an item photo: one shared dialog, the same camera helper as the borrower photo.
                camTarget: null, camTitle: 'Take photo', camError: '', camCanSwitch: false, cam: null,
                async openCamera(target) {
                    this.camTarget = target; this.camError = '';
                    this.camTitle = target.kind === 'item' ? 'Photo of item ' + (target.index + 1) : (this.docDefs.find(d => d.key === target.key) || {}).label || 'Take photo';
                    const dialog = this.$refs.camDialog;
                    if (!dialog.open) dialog.showModal();
                    try {
                        await window.loadPhotoTools(); // the camera code loads on first use
                        this.cam = this.cam || new window.PhotoCamera(this.$refs.camVideo);
                        await this.cam.start();
                        this.camCanSwitch = await this.cam.canSwitch();
                    } catch (e) {
                        this.camError = window.cameraError ? window.cameraError(e) : 'The camera could not start. Check your connection and try again.';
                    }
                },
                async switchCamera() {
                    try {
                        if (await this.cam.switchFacing() === 'unavailable') this.camError = 'The other camera is unavailable, so this one stays on.';
                    } catch (e) { this.camError = window.cameraError(e); }
                },
                captureItemPhoto() {
                    const v = this.$refs.camVideo;
                    if (!v.videoWidth || !v.videoHeight) { this.camError = 'Wait for the camera preview, then take the photo.'; return; }
                    const canvas = document.createElement('canvas');
                    canvas.width = v.videoWidth; canvas.height = v.videoHeight;
                    canvas.getContext('2d').drawImage(v, 0, 0);
                    canvas.toBlob(blob => {
                        if (!blob) { this.camError = 'Capture failed. Try again, or use Add photo.'; return; }
                        const shot = new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' });
                        if (this.camTarget.kind === 'item') this.setItemPhoto(this.camTarget.index, shot); else this.setDoc(this.camTarget.key, shot);
                        this.closeCamera();
                    }, 'image/jpeg', 0.92);
                },
                closeCamera() {
                    this.cam?.stop();
                    if (this.$refs.camDialog?.open) this.$refs.camDialog.close();
                    this.camTarget = null; this.camError = '';
                },

                clearItemPhoto(index) {
                    const item = this.items[index];
                    if (item.photoUrl) URL.revokeObjectURL(item.photoUrl);
                    item.photoFile = null; item.photoUrl = ''; item.photoError = '';
                },

                focusItem(index) {
                    document.getElementById('it-desc-' + index)?.focus();
                },

                itemErr(index) {
                    return this.itemErrors[index] || '';
                },

                // ── Weights and purity. Mirrors App\Services\MetalRegistry (netWeight, fineWeightMultiplier, purityInRange);
                // the server is the authority and recomputes everything. Gold purity = karat, silver = fineness.
                purityOptions(metal) {
                    return this.purityTable[metal] || [];
                },
                purityPickFor(item) {
                    if (item.metal_type === 'other') return '';
                    return this.purityOptions(item.metal_type).some(o => o.value === Number(item.purity)) ? String(Number(item.purity)) : 'other';
                },
                purityOk(metal, p) {
                    const [lo, hi] = metal === 'silver' ? [100, 999.99] : [1, 24];
                    return p >= lo && p <= hi && Math.abs(Math.round(p * 100) / 100 - p) < 1e-9;
                },
                // Share of pure metal. Standard purities use the server's multiplier; "Other" = karat / 24 (gold) or fineness / 1000 (silver).
                fineMultiplier(metal, purity) {
                    const p = Number(purity);
                    const preset = this.purityOptions(metal).find(o => o.value === p);
                    if (preset) return preset.multiplier;
                    return this.purityOk(metal, p) ? p / (metal === 'silver' ? 1000 : 24) : 0;
                },
                purityNote(item) {
                    if (item.metal_type === 'other' || item.purity === '' || item.purity === null) return item.purityPick === 'other' ? 'Enter the purity.' : '';
                    const m = this.fineMultiplier(item.metal_type, item.purity);
                    if (!m) return item.metal_type === 'silver' ? 'Silver purity is 100 to 999.99.' : 'Gold purity is 1 to 24 karat.';
                    return (item.metal_type === 'silver' ? item.purity : item.purity + 'K') + ' = ' + (m * 100).toFixed(2) + ' % pure';
                },
                // PHP round(x, 6). Identical for every standard purity; a custom fractional purity can differ by 0.000001 g at an exact tie (not shown, the server recomputes).
                round6(v) {
                    return Math.round(v * 1e6) / 1e6;
                },
                netWeight(item) {
                    return this.round6(Math.max(0, (Number(item.gross_weight) || 0) - (Number(item.stone_weight) || 0)));
                },
                stoneTooHeavy(item) {
                    return (Number(item.gross_weight) || 0) > 0 && this.netWeight(item) <= 0;
                },

                pickPurity(index, value) {
                    const item = this.items[index];
                    item.purityPick = value;
                    item.purity = value === 'other' ? '' : Number(value);
                    if (value === 'other') this.$nextTick(() => document.getElementById('it-purity-custom-' + index)?.focus());
                    this.recalcItem(index);
                },

                // A new metal gets a valid default purity (gold 22K, silver 925), never the old metal's value.
                onMetalChange(index) {
                    const item = this.items[index];
                    if (item.metal_type === 'other') {
                        // Other = always appraised; clear metal-only fields.
                        item.value_mode = 'appraised';
                        item.purity = '';
                        item.purityPick = '';
                    } else {
                        item.purity = item.metal_type === 'silver' ? 925 : 22;
                        item.purityPick = String(item.purity);
                    }
                    this.recalcItem(index);
                },

                removeItem(index) {
                    if (this.items.length > 1) {
                        if (this.items[index].photoUrl) URL.revokeObjectURL(this.items[index].photoUrl);
                        this.items.splice(index, 1);
                    }
                },

                // An item the clerk has typed into is never dropped by a stray tap: ask first.
                confirmRemove(index) {
                    const i = this.items[index];
                    const typed = (i.description || '').trim() || i.gross_weight > 0 || i.market_value > 0 || i.photoFile;
                    if (!typed || window.confirm('Remove item ' + (index + 1) + '?')) this.removeItem(index);
                },

                // "Gold rate today" fills each gold item's rate that is empty or still the previous rate; a rate typed per item is kept.
                applyGoldRate() {
                    const prev = this.lastGoldRate;
                    this.items.forEach((it, i) => {
                        if (it.metal_type === 'gold' && (!it.rate_per_gram_at_pledge || it.rate_per_gram_at_pledge === prev)) { it.rate_per_gram_at_pledge = this.goldRateOnDate || 0; }
                        this.recalcItem(i);
                    });
                    this.lastGoldRate = this.goldRateOnDate || 0;
                },

                recalcItem(index) {
                    let item = this.items[index];
                    const appraised = item.metal_type === 'other' || item.value_mode === 'appraised';
                    // Net and fine weight are worked out for every item; 'other' has no purity, so no fine weight.
                    item.net_metal_weight = this.netWeight(item);
                    // Fine weight is stored to 6 places; the value is worked from the unrounded figure, as the server does.
                    const fine = item.metal_type === 'other' ? 0 : item.net_metal_weight * this.fineMultiplier(item.metal_type, item.purity);
                    item.fine_weight = this.round6(fine);
                    if (appraised) {
                        // The typed market value is authoritative.
                        item.market_value = Number(item.market_value) || 0;
                    } else {
                        const rate = item.rate_per_gram_at_pledge || 0; // the server values an item only from its own rate
                        item.market_value = fine * rate;
                    }
                    item.loan_value = item.market_value * (this.ltvPercent / 100);
                },

                prepareSubmit(e) {
                    if (this.submitting) { e.preventDefault(); return; } // one tap = one loan; the key below also guards it server-side
                    // a photo still being shrunk would be left out: wait for it (one tap = one loan, so the person taps again)
                    if (this.items.some(i => i.photoBusy) || Object.values(this.docs).some(d => d.busy)) { e.preventDefault(); window.showToast?.('A photo is still being prepared. Try again in a moment.'); return; }
                    // Recalculate all items before submit
                    this.items.forEach((_, i) => this.recalcItem(i));
                    // each item's own photo goes into the file input named for its position, so the server pairs them right
                    this.docDefs.forEach(d => {
                        const el = document.getElementById('doc-submit-' + d.key);
                        if (!el) return;
                        const dt = new DataTransfer();
                        if (this.docs[d.key].file) dt.items.add(this.docs[d.key].file);
                        el.files = dt.files;
                    });
                    this.items.forEach((it, i) => {
                        const el = document.getElementById('it-photo-submit-' + i);
                        if (!el) return;
                        const dt = new DataTransfer();
                        if (it.photoFile) dt.items.add(it.photoFile);
                        el.files = dt.files;
                    });
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
