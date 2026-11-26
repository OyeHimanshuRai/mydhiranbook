@php
    // Item tags for several loans at once (picked on the Loans list): one slip per loan on the bill roll, a page break between slips.
    // Same slip and same sizes as the single tag page (58 mm, or 80 mm with ?w=80).
    $w = (int) ($width ?? 58);
    $n = $loans->count();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Item Tags &mdash; {{ $n }} {{ $n === 1 ? 'loan' : 'loans' }}</title>
    <style>
        @include('dhiran._tag-styles', ['w' => $w])
        .tag-count { margin-right: auto; font-weight: 600; }
        .tag-bar { flex-wrap: wrap; }
        @media print { .tag { break-after: page; page-break-after: always; } .tag:last-of-type { break-after: auto; page-break-after: auto; } }
    </style>
</head>
<body>
    <div class="tag-bar">
        <span class="tag-count" role="status">{{ $n }} {{ $n === 1 ? 'tag' : 'tags' }}</span>
        <a href="{{ request()->fullUrlWithQuery(['w' => $w === 58 ? 80 : 58]) }}">{{ $w === 58 ? 'Use 80 mm' : 'Use 58 mm' }}</a>
        <button type="button" class="tag-btn" onclick="window.print()">Print {{ $n === 1 ? 'tag' : 'all tags' }}</button>
    </div>
    <main>
        @foreach($loans as $tagLoan)
            @include('dhiran._tag-slip', ['loan' => $tagLoan, 'shop' => $shop])
        @endforeach
    </main>
</body>
</html>
