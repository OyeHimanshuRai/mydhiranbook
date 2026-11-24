@php
    // The slip put in or stuck on the bag/packet of pledged items kept in the locker. Bill size: 58 mm wide by default (the usual bill printer
    // roll), 80 mm with ?w=80. Prints only as tall as its content; no switches, no money other than the principal, no mobile number.
    $w = (int) ($width ?? 58);
    $items = ($loan->items ?? collect())->where('status', 'pledged')->values();
    $fmt = fn ($n) => number_format((float) $n, 3);
    $purity = fn ($i) => ($i->metal_type ?? 'gold') === 'other' ? '' : rtrim(rtrim((string) $i->purity, '0'), '.') . (($i->metal_type ?? 'gold') === 'silver' ? '' : 'K');
    $metal = fn ($i) => ucfirst($i->metal_type ?? 'gold');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Item Tag &mdash; {{ $loan->loan_number }}</title>
    <style>
        @page { size: {{ $w }}mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #000; font: 10px/1.3 "Helvetica Neue", Arial, sans-serif; }
        .tag-bar { width: {{ $w }}mm; margin: 12px auto 0; display: flex; gap: 6px; justify-content: flex-end; align-items: center; font-size: 12px; }
        .tag-bar a { color: #111; }
        .tag-btn { border: 1px solid #111; background: #111; color: #fff; padding: 8px 14px; border-radius: 4px; font: 600 13px/1 inherit; cursor: pointer; min-height: 40px; }
        .tag { width: {{ $w }}mm; margin: 10px auto 24px; padding: 2mm; background: #fff; border: 1px dashed #666; } /* the dashed edge is the cutting guide; it prints too */
        .tag-shop { text-align: center; font-size: 8px; text-transform: uppercase; letter-spacing: .06em; }
        .tag-no { text-align: center; font: 700 {{ $w >= 80 ? 24 : 19 }}px/1.15 "Courier New", monospace; margin: 1px 0 3px; }
        .tag-line { display: flex; justify-content: space-between; gap: 6px; border-top: 1px dotted #777; padding: 1px 0; }
        .tag-line b { text-align: right; }
        .tag-item { border-top: 1px dotted #777; padding: 2px 0; }
        .tag-item small { display: block; font-size: 9px; }
        .tag-total { border-top: 1px solid #000; padding-top: 2px; font-weight: 700; }
        .tag-write { display: flex; gap: 6px; margin-top: 5px; font-size: 8px; }
        .tag-write div { flex: 1; } .tag-write span { display: block; height: 14px; border-bottom: 1px solid #000; }
        @media print { body { background: #fff; } .tag-bar { display: none; } .tag { margin: 0; } }
    </style>
</head>
<body>
    <div class="tag-bar">
        <a href="{{ request()->fullUrlWithQuery(['w' => $w === 58 ? 80 : 58]) }}">{{ $w === 58 ? 'Use 80 mm' : 'Use 58 mm' }}</a>
        <button type="button" class="tag-btn" onclick="window.print()">Print tag</button>
    </div>
    <div class="tag">
        <div class="tag-shop">{{ $shop->name ?? 'Dhiran' }}</div>
        <div class="tag-no">{{ $loan->loan_number }}</div>
        <div class="tag-line"><span>Borrower</span><b>{{ $loan->customer->name ?? '---' }}</b></div>
        <div class="tag-line"><span>Date</span><b>{{ $loan->loan_date?->format('d M Y') }}</b></div>
        <div class="tag-line"><span>Maturity</span><b>{{ $loan->maturity_date?->format('d M Y') }}</b></div>
        <div class="tag-line"><span>Principal</span><b>₹{{ number_format($loan->principal_amount, 2) }}</b></div>
        @forelse($items as $n => $item)
            <div class="tag-item">
                {{ $n + 1 }}. {{ $item->description }}
                <small>{{ $metal($item) }}{{ $purity($item) ? ' ' . $purity($item) : '' }} · Gross {{ $fmt($item->gross_weight) }} g · Net {{ $fmt($item->net_metal_weight) }} g{{ $item->huid ? ' · HUID ' . $item->huid : '' }}</small>
            </div>
        @empty
            <div class="tag-item">No pledged items left on this loan.</div>
        @endforelse
        @if($items->isNotEmpty())
            <div class="tag-total">{{ $items->count() }} {{ $items->count() === 1 ? 'item' : 'items' }} · Gross {{ $fmt($items->sum('gross_weight')) }} g · Net {{ $fmt($items->sum('net_metal_weight')) }} g</div>
        @endif
        <div class="tag-write">
            <div>Locker / rack no.<span></span></div>
            <div>Packed by<span></span></div>
        </div>
    </div>
</body>
</html>
