@php
    // The slip put in or stuck on the bag/packet of pledged items kept in the locker. Bill size: 58 mm wide by default (the usual bill printer
    // roll), 80 mm with ?w=80. Prints only as tall as its content; no switches, no money other than the principal, no mobile number.
    $w = (int) ($width ?? 58);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Item Tag &mdash; {{ $loan->loan_number }}</title>
    <style>
        @include('dhiran._tag-styles', ['w' => $w])
    </style>
</head>
<body>
    <div class="tag-bar">
        <a href="{{ request()->fullUrlWithQuery(['w' => $w === 58 ? 80 : 58]) }}">{{ $w === 58 ? 'Use 80 mm' : 'Use 58 mm' }}</a>
        <button type="button" class="tag-btn" onclick="window.print()">Print tag</button>
    </div>
    @include('dhiran._tag-slip', ['loan' => $loan, 'shop' => $shop])
</body>
</html>
