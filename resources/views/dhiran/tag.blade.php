@php
    // The slip stuck on the bag or packet that holds the pledged items in the locker. Plain print page: no switches, no money other than the principal.
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
        @page { size: auto; margin: 6mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #111; font: 12px/1.35 "Helvetica Neue", Arial, sans-serif; }
        .tag-bar { max-width: 100mm; margin: 12px auto 0; padding: 0 8px; display: flex; gap: 8px; justify-content: flex-end; }
        .tag-btn { border: 1px solid #111; background: #111; color: #fff; padding: 8px 14px; border-radius: 4px; font: inherit; font-weight: 600; cursor: pointer; min-height: 40px; }
        .tag { width: 100mm; margin: 12px auto 24px; padding: 4mm; background: #fff; border: 1.5px dashed #555; } /* the dashed edge is the cutting guide */
        .tag-shop { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #444; text-align: center; }
        .tag-no { font: 700 30px/1.1 "Courier New", monospace; text-align: center; margin: 2px 0 4px; letter-spacing: .03em; }
        .tag-rows { margin: 0; border-top: 1px solid #111; }
        .tag-row { display: flex; justify-content: space-between; gap: 10px; padding: 2px 0; border-bottom: 1px dotted #aaa; }
        .tag-row dt { color: #444; margin: 0; } .tag-row dd { margin: 0; font-weight: 600; text-align: right; }
        .tag-items { width: 100%; border-collapse: collapse; margin: 6px 0; font-size: 11px; }
        .tag-items th, .tag-items td { border: 1px solid #777; padding: 2px 4px; text-align: left; vertical-align: top; }
        .tag-items th { background: #eee; font-size: 10px; text-transform: uppercase; }
        .tag-items .r { text-align: right; white-space: nowrap; }
        .tag-items tfoot td { font-weight: 700; background: #fafafa; }
        .tag-write { display: flex; gap: 8px; margin-top: 8px; }
        .tag-write div { flex: 1; font-size: 10px; color: #444; }
        .tag-write span { display: block; height: 20px; border-bottom: 1px solid #111; }
        @media print { body { background: #fff; } .tag-bar { display: none; } .tag { margin: 0 auto; } }
    </style>
</head>
<body>
    <div class="tag-bar"><button type="button" class="tag-btn" onclick="window.print()">Print tag</button></div>
    <div class="tag">
        <div class="tag-shop">{{ $shop->name ?? 'Dhiran' }}</div>
        <div class="tag-no">{{ $loan->loan_number }}</div>
        <dl class="tag-rows">
            <div class="tag-row"><dt>Borrower</dt><dd>{{ $loan->customer->name ?? '---' }}</dd></div>
            <div class="tag-row"><dt>Loan date</dt><dd>{{ $loan->loan_date?->format('d M Y') }}</dd></div>
            <div class="tag-row"><dt>Maturity</dt><dd>{{ $loan->maturity_date?->format('d M Y') }}</dd></div>
            <div class="tag-row"><dt>Principal</dt><dd>₹{{ number_format($loan->principal_amount, 2) }}</dd></div>
        </dl>
        <table class="tag-items">
            <thead><tr><th>#</th><th>Item</th><th class="r">Gross g</th><th class="r">Net g</th></tr></thead>
            <tbody>
                @forelse($items as $n => $item)
                    <tr>
                        <td>{{ $n + 1 }}</td>
                        <td>{{ $item->description }}<br><small>{{ $metal($item) }}{{ $purity($item) ? ' ' . $purity($item) : '' }}{{ $item->huid ? ' · HUID ' . $item->huid : '' }}</small></td>
                        <td class="r">{{ $fmt($item->gross_weight) }}</td>
                        <td class="r">{{ $fmt($item->net_metal_weight) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No pledged items left on this loan.</td></tr>
                @endforelse
            </tbody>
            @if($items->isNotEmpty())
                <tfoot><tr><td colspan="2">{{ $items->count() }} {{ $items->count() === 1 ? 'item' : 'items' }}</td><td class="r">{{ $fmt($items->sum('gross_weight')) }}</td><td class="r">{{ $fmt($items->sum('net_metal_weight')) }}</td></tr></tfoot>
            @endif
        </table>
        <div class="tag-write">
            <div>Locker / rack no.<span></span></div>
            <div>Packed by<span></span></div>
        </div>
    </div>
</body>
</html>
