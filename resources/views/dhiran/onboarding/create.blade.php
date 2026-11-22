<x-subscription-page title="Set up your Dhiran business">
    <div class="ck ck-narrow">
        <h1>Set up your Dhiran business</h1>
        <p class="lede">Just a few details to get your pledge loan shop ready.</p>
        <form method="POST" action="{{ route('dhiran.onboarding.store') }}" data-turbo="false" data-once class="ui-card">@csrf
            <div class="ck-grid">
                <x-site.field wide name="name" label="Business name" :value="old('name')" required autofocus maxlength="255" autocomplete="organization" placeholder="e.g. Sharma Gold Finance" />
                <x-site.field name="phone" label="Business phone" type="tel" :value="old('phone')" required inputmode="numeric" maxlength="16" autocomplete="tel" placeholder="10-digit number" />
                <x-site.field name="loan_number_prefix" label="Loan receipt prefix <span class='opt'>(optional)</span>" :value="old('loan_number_prefix')" maxlength="12" autocapitalize="characters" placeholder="e.g. SGF" />
                <x-site.field wide name="address" label="Business address" :value="old('address')" required maxlength="500" autocomplete="street-address" placeholder="Street, area, landmark" />
            </div>
            <button type="submit" class="ui-btn ui-btn--gold ui-btn--block ui-submit" data-busy="Setting up…">Finish setup &amp; open dashboard</button>
        </form>
    </div>
</x-subscription-page>
