<x-dhiran-layout title="Add Customer">
    @vite('resources/css/dhiran-forms.css')
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Add Customer</h1>
            <p class="text-sm text-gray-500 mt-1">Save a customer's details now; start a loan for them whenever they need one.</p>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Back to Customers" :href="route('dhiran.borrowers.index')" />
        </div>
    </x-dhiran.page-header>

    @php
        // [name, label, type, required, maxlength, extra attributes]
        $fields = [
            ['first_name', 'First name', 'text', true, 255, 'autofocus'],
            ['last_name', 'Last name', 'text', false, 255, ''],
            ['mobile', 'Mobile number', 'tel', true, 10, 'inputmode="numeric" placeholder="10 digits"'],
            ['pan', 'PAN', 'text', false, 10, 'placeholder="ABCDE1234F" autocapitalize="characters" spellcheck="false" style="text-transform:uppercase"'],
            ['id_number', 'ID number', 'text', false, 50, ''],
            ['state_code', 'State code', 'text', false, 10, ''],
        ];
    @endphp

    <div class="content-inner">
        <form method="POST" action="{{ route('dhiran.customers.store') }}" id="bc-form" class="fm-page fm-page--narrow"
              x-data="{ busy: false }" @submit="busy = !$event.defaultPrevented" @pageshow.window="busy = false" @turbo:submit-end="if (!$event.detail.success) busy = false">
            @csrf
            @if($errors->any())
                <div class="fm-alert" role="alert" tabindex="-1" x-init="$nextTick(() => $el.focus())">
                    <x-icon name="circle-alert" size="20" />
                    <div>
                        <p class="fm-alert-title">The customer was not saved. Please fix:</p>
                        <ul>
                            @foreach($errors->keys() as $k)
                                <li><a href="#{{ in_array($k, ['first_name', 'last_name', 'mobile', 'pan', 'id_number', 'state_code', 'address', 'notes'], true) ? 'bc-' . $k : 'bc-form' }}">{{ $errors->first($k) }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <section class="fm-card" aria-labelledby="bc-h">
                <header class="fm-card-head">
                    <div>
                        <h2 class="fm-title" id="bc-h">Customer details</h2>
                        <p class="fm-desc">Name and mobile number are needed.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <div class="fm-grid">
                        @foreach($fields as [$f, $label, $type, $req, $max, $extra])
                            <div class="fm-field">
                                <label class="fm-label" for="bc-{{ $f }}">{{ $label }}@if($req) <x-req />@endif</label>
                                <input type="{{ $type }}" id="bc-{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" @if($req) required aria-required="true" @endif maxlength="{{ $max }}" class="fm-control" autocomplete="off" {!! $extra !!}
                                       @error($f) aria-invalid="true" aria-describedby="bc-err-{{ $f }}" @enderror>
                                @error($f)<p class="fm-error" id="bc-err-{{ $f }}" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                        <div class="fm-field fm-wide">
                            <label class="fm-label" for="bc-address">Address</label>
                            <textarea id="bc-address" name="address" rows="3" maxlength="500" class="fm-control" @error('address') aria-invalid="true" aria-describedby="bc-err-address" @enderror>{{ old('address') }}</textarea>
                            @error('address')<p class="fm-error" id="bc-err-address" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>@enderror
                        </div>
                        <div class="fm-field fm-wide">
                            <label class="fm-label" for="bc-notes">Notes</label>
                            <textarea id="bc-notes" name="notes" rows="2" maxlength="1000" class="fm-control" @error('notes') aria-invalid="true" aria-describedby="bc-err-notes" @enderror>{{ old('notes') }}</textarea>
                            @error('notes')<p class="fm-error" id="bc-err-notes" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </section>

            <div class="fm-bar" role="region" aria-label="Save this customer">
                <p class="fm-bar-text">You can start a loan for them right after.</p>
                <div class="fm-bar-acts">
                    <a href="{{ route('dhiran.borrowers.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy.toString()"><x-icon name="save" size="16" /> <span x-text="busy ? 'Saving…' : 'Save customer'">Save customer</span></button>
                </div>
            </div>
        </form>
    </div>
</x-dhiran-layout>
