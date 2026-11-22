{{-- A labelled rupee amount field: ₹ prefix, decimal keypad on phones, 16px text. The submit values are unchanged (a plain number). Always required, so it always carries the star. --}}
@props(['id', 'name', 'label', 'min' => null, 'autofocus' => false])
<div>
    <label for="{{ $id }}">{{ $label }} <x-req /></label>
    <div class="dd-money">
        <span aria-hidden="true">₹</span>
        <input type="number" step="0.01" @if($min !== null) min="{{ $min }}" @endif inputmode="decimal" autocomplete="off" id="{{ $id }}" name="{{ $name }}" required aria-required="true" placeholder="0.00"
               class="dh-form-control dd-input" @if($autofocus) autofocus @endif>
    </div>
</div>
