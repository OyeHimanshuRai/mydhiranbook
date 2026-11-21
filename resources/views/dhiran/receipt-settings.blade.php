{{-- Receipt settings: a checked switch = printed (saved as the hidden list), plus the texts printed on the pledge receipt and closure certificate. --}}
<x-dhiran-layout title="Receipt settings">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Receipt settings</h1>
            <p class="text-sm text-gray-500 mt-1">What is printed on receipts and certificates</p>
        </div>
    </x-dhiran.page-header>
@php($hidden = $settings->hidden_receipt_fields ?? [])
<style>
    .rset { margin-top: 0; border: 1px solid var(--dset-border, #e5e7eb); border-radius: 12px; padding: 18px 20px; background: var(--dset-card, #fff); }
    .rset-title { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
    .rset-lead { font-size: 12px; color: var(--dset-muted, #6b7280); margin: 0 0 16px; }
    .rset-receipt { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px 28px; margin-bottom: 18px; }
    .rset-receipt-title { grid-column: 1 / -1; font-size: 13px; font-weight: 700; border-bottom: 1px solid var(--dset-border, #e5e7eb); padding-bottom: 6px; margin: 0; }
    .rset-group { border: 0; padding: 0; margin: 0; min-width: 0; }
    .rset-group legend { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--dset-muted, #6b7280); padding: 0; margin-bottom: 6px; }
    .rset-switch { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 6px 0; font-size: 13px; cursor: pointer; }
    .rset-switch input { appearance: none; flex: none; width: 34px; height: 20px; border-radius: 10px; background: #cbd5e1; position: relative; cursor: pointer; transition: background .15s; margin: 0; }
    .rset-switch input::after { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; }
    .rset-switch input:checked { background: #111827; }
    .rset-switch input:checked::after { transform: translateX(14px); }
    .rset-switch input:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .rset-switch input:disabled { opacity: .55; cursor: not-allowed; }
    .rset-note { font-size: 11px; color: var(--dset-muted, #6b7280); margin: 0 0 6px; }
</style>
<div class="content-inner dhiran-settings-root">
<x-dhiran.settings-nav active="receipt" />
<section class="rset" id="receipt">
    <h2 class="rset-title">Receipt fields</h2>
    <p class="rset-lead">Choose what is printed on customer receipts. Switched-off fields are left out and the layout closes up.</p>
    <form method="POST" action="{{ route('dhiran.settings.receipt') }}" data-turbo-frame="_top">
        @csrf
        @method('PATCH')
        @foreach(\App\Models\Dhiran\DhiranSettings::RECEIPT_FIELDS as $receipt => $def)
            <div class="rset-receipt">
                <h3 class="rset-receipt-title">{{ $def['label'] }}</h3>
                @if($loop->first)
                    <fieldset class="rset-group">
                        <legend>Always printed</legend>
                        <label class="rset-switch"><span>Loan number</span><input type="checkbox" checked disabled></label>
                        <p class="rset-note">Always shown: it is how a loan is found in the directory.</p>
                    </fieldset>
                @endif
                @foreach($def['groups'] as $group => $fields)
                    <fieldset class="rset-group">
                        <legend>{{ $group }}</legend>
                        @foreach($fields as $key => $label)
                            <label class="rset-switch">
                                <span>{{ $label }}</span>
                                <input type="checkbox" role="switch" name="show[]" value="{{ $receipt }}.{{ $key }}" @checked(! in_array("$receipt.$key", $hidden, true))>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
        @endforeach
        <h3 class="rset-receipt-title">Printed texts</h3>
        <div class="dset-fields" style="margin:12px 0 18px">
            <div class="dset-field--wide">
                <label class="dset-label" for="ds-terms">Terms printed on the pledge receipt</label>
                <textarea id="ds-terms" name="receipt_terms_text" rows="3" maxlength="5000" class="dset-input dset-textarea">{{ old('receipt_terms_text', $settings->receipt_terms_text) }}</textarea>
            </div>
            <div class="dset-field--wide">
                <label class="dset-label" for="ds-closure">Text on the closure certificate</label>
                <textarea id="ds-closure" name="closure_certificate_text" rows="2" maxlength="5000" class="dset-input dset-textarea">{{ old('closure_certificate_text', $settings->closure_certificate_text) }}</textarea>
            </div>
        </div>
        <div class="dset-actions">
            <button type="submit" class="btn btn-dark btn-sm"><x-icon name="save" size="14" class="mr-1" /> Save receipt settings</button>
        </div>
    </form>
</section>
</div>
</x-dhiran-layout>
