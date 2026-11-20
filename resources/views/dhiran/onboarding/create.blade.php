<x-subscription-page title="Set up your Dhiran business">
    <style>.dob-wrap{max-width:560px}.dob-field{margin-bottom:14px}.dob-field label{display:block;font-weight:600;margin-bottom:4px}.dob-field input{width:100%}.dob-err{color:#b91c1c;font-size:13px}.opt{font-weight:400;color:#64748b}</style>
    <div class="dob-wrap">
        <div class="dob-head">
            <h1>Set up your Dhiran business</h1>
            <p>Just a few details to get your pledge loan shop ready.</p>
        </div>

        <div class="dob-card">
            <form method="POST" action="{{ route('dhiran.onboarding.store') }}" data-turbo="false">
                @csrf
                <div class="dob-grid">
                    <div class="dob-field full">
                        <label for="name">Business name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus maxlength="255" placeholder="e.g. Sharma Gold Finance">
                        @error('name') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="phone">Business phone</label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required inputmode="numeric" maxlength="16" placeholder="10-digit number">
                        @error('phone') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field full">
                        <label for="address">Business address</label>
                        <input id="address" name="address" type="text" value="{{ old('address') }}" required maxlength="500" placeholder="Street, area, landmark">
                        @error('address') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="loan_number_prefix">Loan receipt prefix <span class="opt">(optional)</span></label>
                        <input id="loan_number_prefix" name="loan_number_prefix" type="text" value="{{ old('loan_number_prefix') }}" maxlength="12" placeholder="e.g. SGF">
                        @error('loan_number_prefix') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="dob-foot">
                    <button type="submit" class="gold">Finish setup &amp; open dashboard</button>
                </div>
            </form>
        </div>
    </div>
</x-subscription-page>
