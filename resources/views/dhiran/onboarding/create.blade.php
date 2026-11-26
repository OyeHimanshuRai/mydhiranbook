<x-subscription-page title="Set up your MyDhiranBook business">
    <div class="ck ck-narrow">
        <h1>Set up your MyDhiranBook business</h1>
        <p class="lede">Just a few details. We check each new shop before it opens, and you will wait on the next page until it is approved.</p>
        <form method="POST" action="{{ route('dhiran.onboarding.store') }}" data-turbo="false" data-once data-validate class="ui-card">@csrf
            <div class="ck-grid">
                <x-site.field wide name="name" label="Business name" req :value="old('name')" required autofocus maxlength="255" autocomplete="organization" placeholder="e.g. Sharma Gold Finance" />
                <x-site.field name="phone" label="Business phone" type="tel" req :value="old('phone')" required inputmode="numeric" maxlength="16" autocomplete="tel" data-rule="mobile" placeholder="10-digit number" />
                <x-site.field name="loan_number_prefix" label="Loan receipt prefix" hint="Printed before every loan number." :value="old('loan_number_prefix')" maxlength="10" autocapitalize="characters" data-rule="prefix" placeholder="e.g. SGF" />
                <x-site.field wide name="address" label="Business address" req :value="old('address')" required maxlength="500" autocomplete="street-address" placeholder="Street, area, landmark" />
                <x-site.field wide name="referred_by" label="Who told you about MyDhiranBook?" hint="A shop referral code, name or phone." :value="old('referred_by', $ref)" maxlength="80" autocomplete="off" />
            </div>
            <button type="submit" class="ui-btn ui-btn--block ui-submit" data-busy="Sending…">Send for approval</button>
        </form>
    </div>
</x-subscription-page>
