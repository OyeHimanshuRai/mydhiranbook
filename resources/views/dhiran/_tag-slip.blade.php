{{-- One Item Tag slip for $loan (with customer and items loaded) of $shop. Shared by the single and the bulk tag pages. --}}
@php
    $items = ($loan->items ?? collect())->where('status', 'pledged')->values();
    $fmt = fn ($n) => number_format((float) $n, 3);
    $purity = fn ($i) => ($i->metal_type ?? 'gold') === 'other' ? '' : rtrim(rtrim((string) $i->purity, '0'), '.') . (($i->metal_type ?? 'gold') === 'silver' ? '' : 'K');
    $metal = fn ($i) => ucfirst($i->metal_type ?? 'gold');
@endphp
<div class="tag">
        <div class="tag-shop">{{ $shop->name ?? 'MyDhiranBook' }}</div>
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
