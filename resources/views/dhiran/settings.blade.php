<x-dhiran-layout title="Loan settings" settings footer>
    <x-dhiran.page-header>
        <x-dhiran.settings-nav active="loan" as="title" />
    </x-dhiran.page-header>

    {{-- Every field here is a DhiranSettings column that loans read (2026-11-08: the old form used names the save did not
         accept, so it never saved, and showed placeholder values). Existing loans keep their own terms. --}}
    @php
        $v = fn (string $key, $fallback = null) => old($key, $settings->{$key} ?? $fallback);
        // 2.50 shows as 2.5, 5000000.00 as 5000000: the stored number, not padded zeros.
        $num = fn (string $key) => is_numeric($v($key)) ? rtrim(rtrim(number_format((float) $v($key), 2, '.', ''), '0'), '.') : $v($key);
    @endphp

    <turbo-frame id="settings-pane" data-turbo-action="advance">
    <div class="content-inner">
        <x-dhiran.settings-nav active="loan" switcher />
        <x-settings.live />
        <p class="st-lead">Defaults for every new pledge loan. Loans already made keep the terms they were made with.</p>
        <form method="POST" action="{{ route('dhiran.settings.update') }}" class="st-page" data-turbo-frame="_top"
              x-data="stSaveForm({{ session()->hasOldInput() ? 'true' : 'false' }})" @input="dirty = true" @change="dirty = true" @submit="submit($event)"
              @beforeunload.window="if (dirty && !saving) $event.preventDefault()">
            @csrf
            @method('PATCH')

            <div class="st-stack" data-settings-board>
                <x-settings.panel title="Interest" desc="Applied to every new loan.">
                    <div class="st-grid">
                        <x-settings.field name="default_interest_rate_monthly" id="ds-rate" label="Monthly interest rate" type="number" inputmode="decimal" step="0.01" min="0" max="10" req required suffix="% per month" :value="$num('default_interest_rate_monthly')" />
                        <x-settings.field name="default_interest_type" id="ds-type" label="Interest type" type="select" req>
                            @foreach(['flat' => 'Flat (Simple)', 'daily' => 'Daily', 'compound' => 'Compound'] as $value => $label)
                                <option value="{{ $value }}" @selected($v('default_interest_type', 'flat') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-settings.field>
                        <x-settings.field name="default_penalty_rate_monthly" id="ds-penalty" label="Penalty rate" type="number" inputmode="decimal" step="0.01" min="0" max="10" req required suffix="% per month" :value="$num('default_penalty_rate_monthly')" />
                        <x-settings.field name="default_min_interest_months" id="ds-minint" label="Minimum interest" type="number" inputmode="numeric" min="0" max="12" step="1" req required suffix="months" :value="$v('default_min_interest_months')" hint="Charged even if the loan is closed or renewed sooner." />
                    </div>
                </x-settings.panel>

                <x-settings.panel title="Loan value (LTV)" desc="How much you lend against the market value of the pledged items.">
                    <div class="st-grid">
                        <x-settings.field name="default_ltv_ratio" id="ds-ltv" label="Loan value" type="number" inputmode="decimal" step="0.01" min="1" max="100" req required suffix="% of market value" :value="$num('default_ltv_ratio')" />
                        <x-settings.field name="high_value_threshold" id="ds-hv-threshold" label="Large loans from" type="number" inputmode="decimal" step="0.01" min="0" req required prefix="₹" inr :value="$num('high_value_threshold')" />
                        <x-settings.field name="high_value_ltv_ratio" id="ds-hv-ltv" label="Large-loan value" type="number" inputmode="decimal" step="0.01" min="1" max="100" req required suffix="%" :value="$num('high_value_ltv_ratio')" hint="Used instead of the normal % for loans of this size or more." />
                    </div>
                </x-settings.panel>

                <x-settings.panel title="Loan limits and fee" desc="The smallest and largest loan a clerk can make, and the fee taken at the start.">
                    <div class="st-grid">
                        <x-settings.field name="min_loan_amount" id="ds-min" label="Minimum loan" type="number" inputmode="decimal" step="0.01" min="0" req required prefix="₹" inr :value="$num('min_loan_amount')" />
                        <x-settings.field name="max_loan_amount" id="ds-max" label="Maximum loan" type="number" inputmode="decimal" step="0.01" min="0" req required prefix="₹" inr :value="$num('max_loan_amount')" />
                        <x-settings.field name="processing_fee_type" id="ds-fee-type" label="Processing fee" type="select" req>
                            <option value="flat" @selected($v('processing_fee_type', 'flat') === 'flat')>Fixed amount (₹)</option>
                            <option value="percent" @selected($v('processing_fee_type') === 'percent')>% of the loan</option>
                        </x-settings.field>
                        <x-settings.field name="processing_fee_value" id="ds-fee" label="Fee value" type="number" inputmode="decimal" step="0.01" min="0" req required :value="$num('processing_fee_value')" hint="In rupees or percent, as set in Processing fee. 0 for no fee." />
                    </div>
                </x-settings.panel>

                <x-settings.panel title="Tenure and forfeiture" desc="How long a loan runs, and what happens when it is not repaid.">
                    <div class="st-grid">
                        <x-settings.field name="default_tenure_months" id="ds-tenure" label="Tenure" type="number" inputmode="numeric" min="1" max="60" step="1" req required suffix="months" :value="$v('default_tenure_months')" />
                        <x-settings.field name="default_min_lock_months" id="ds-lock" label="Lock-in" type="number" inputmode="numeric" min="0" max="60" step="1" req required suffix="months" :value="$v('default_min_lock_months')" hint="The loan cannot be closed early before this." />
                        <x-settings.field name="grace_period_days" id="ds-grace" label="Grace period" type="number" inputmode="numeric" min="0" max="90" step="1" req required suffix="days" :value="$v('grace_period_days')" hint="Days after maturity before penalty applies and a forfeiture notice can be sent." />
                        <x-settings.field name="forfeiture_notice_days" id="ds-notice" label="Forfeiture notice period" type="number" inputmode="numeric" min="0" max="365" step="1" req required suffix="days" :value="$v('forfeiture_notice_days')" hint="Days the customer gets to pay after the notice, before forfeiture." />
                    </div>
                </x-settings.panel>

                <x-settings.panel title="Advance interest" desc="Take the first months' interest when the loan is given.">
                    <div class="st-grid">
                        <x-dhiran.switch id="ds-adv" name="advance_interest_enabled" value="1" label="Allow advance interest" :checked="(bool) $settings->advance_interest_enabled"
                            hint="On each new loan the clerk can choose to deduct the first 1 to 3 months' interest from the cash handed over. It is never refunded if the loan closes early. Some states do not allow deducting interest from the loan (Tamil Nadu does not): check your state's pawnbrokers law." />
                        <x-settings.field name="advance_interest_default_months" id="ds-advm" label="Months taken in advance" type="select">
                            @foreach([1 => '1 month', 2 => '2 months', 3 => '3 months'] as $value => $label)
                                <option value="{{ $value }}" @selected((int) $v('advance_interest_default_months', 1) === $value)>{{ $label }}</option>
                            @endforeach
                        </x-settings.field>
                    </div>
                </x-settings.panel>

                <x-settings.panel title="Loan numbers" desc="The text shown before each loan number.">
                    <div class="st-grid">
                        <x-settings.field name="loan_number_prefix" id="ds-prefix" label="Loan number prefix" type="text" maxlength="10" placeholder="DH-" autocomplete="off" :value="$v('loan_number_prefix')" hint="Numbers continue from the last loan." />
                    </div>
                </x-settings.panel>

                {{-- Owner 2026-11-12: no SMS reminder card. Nothing sends SMS yet (the reminder job's send step is a TODO),
                     so offering "Due-date reminders: On" misled owners. Saving without the field turns the old switch off.
                     ponytail: bring the card back with a real sender (provider + approved templates). --}}
            </div>

            <x-settings.savebar label="Save settings" :cancel-href="route('dhiran.loans')" />
        </form>
    </div>
    </turbo-frame>
</x-dhiran-layout>
