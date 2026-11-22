{{-- The payment-method select every money dialog shares (cash, UPI, card, bank transfer). --}}
@props(['id', 'label' => 'Payment Method', 'autofocus' => false])
<div>
    <label for="{{ $id }}">{{ $label }}</label>
    <select id="{{ $id }}" name="payment_method" class="dh-form-control dh-native-select dd-input" @if($autofocus) autofocus @endif>
        <option value="cash">Cash</option>
        <option value="upi">UPI</option>
        <option value="card">Card</option>
        <option value="bank_transfer">Bank Transfer</option>
    </select>
</div>
