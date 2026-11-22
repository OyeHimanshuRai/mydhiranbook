<x-dhiran-layout title="Cash Book">
    @php
        // $totals (opening, in, out, net, closing) and $pageStartBalance come from the controller: whole period,
        // every page, starting from the balance before the period. With the in/out filter on, rows skip the other
        // kind, so a per-row running balance would be wrong: it is left out.
        $fromDate = $from;
        $showRunning = ! $filters->get('cash');
    @endphp

    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Dhiran Cash Book</h1>
            <p class="text-sm text-gray-500 mt-1">All cash inflows and outflows for pledge (gold & silver) loans</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <x-dhiran.btn-collapse icon="printer" label="Print" onclick="window.print()" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-bar :filters="$filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request())" :action="route('dhiran.reports.cashbook')" default-period="this_month"
            :fields="['period', 'cash']" />

        {{-- Summary Cards --}}
        <div class="dh-stats">
            <x-dhiran.stat money label="Opening balance" :value="\App\Support\IndianNumber::format($totals['opening'], 2)" icon="wallet" total="opening"
                :sub="'Before ' . \Illuminate\Support\Carbon::parse($fromDate)->format('d M Y')" />
            <x-dhiran.stat money label="Total inflows" :value="\App\Support\IndianNumber::format($totals['in'], 2)" icon="trending-up" total="in" tone="success" />
            <x-dhiran.stat money label="Total outflows" :value="\App\Support\IndianNumber::format($totals['out'], 2)" icon="trending-down" total="out" tone="danger" />
            <x-dhiran.stat money label="Closing balance" :value="\App\Support\IndianNumber::format($totals['closing'], 2)" icon="chart-no-axes-column" total="closing"
                :tone="$totals['closing'] >= 0 ? null : 'danger'" :sub="'Net ' . \App\Support\IndianNumber::format($totals['net'], 2) . ' in this period'" />
        </div>

        {{-- Table --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Dhiran cashbook entries">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Date</th>
                            <th scope="col">Loan #</th>
                            <th scope="col" class="text-center">Type</th>
                            <th scope="col" data-low="1">Source</th>
                            <th scope="col" class="text-right">Amount</th>
                            <th scope="col">Method</th>
                            <th scope="col" data-low="1">Description</th>
                            <th scope="col" class="text-right">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $runningBalance = $pageStartBalance; @endphp
                        @forelse($entries as $entry)
                            @php
                                if ($entry->type === 'in') {
                                    $runningBalance += (float) $entry->amount;
                                } else {
                                    $runningBalance -= (float) $entry->amount;
                                }
                            @endphp
                            <tr>
                                <x-dhiran.sno :rows="$entries" :n="$loop->iteration" />
                                <td data-label="Date" data-cell="title" class="whitespace-nowrap">{{ $entry->entry_date?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Loan #" class="whitespace-nowrap">
                                    @if($entry->loan)
                                        <a href="{{ route('dhiran.show', $entry->loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $entry->loan->loan_number }}</a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td data-label="Type" data-cell="status" class="text-center">
                                    @if($entry->type === 'in')
                                        <span class="dh-status dh-status--success">IN</span>
                                    @else
                                        <span class="dh-status dh-status--danger">OUT</span>
                                    @endif
                                </td>
                                <td data-label="Source" data-low="1" class="whitespace-nowrap">{{ ucfirst(str_replace('_', ' ', $entry->source_type ?? '-')) }}</td>
                                <td data-label="Amount" class="whitespace-nowrap text-right font-semibold {{ $entry->type === 'in' ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $entry->type === 'in' ? '+' : '-' }}{{ \App\Support\IndianNumber::format((float) $entry->amount, 2) }}
                                </td>
                                <td data-label="Method" class="whitespace-nowrap">{{ \App\Models\Dhiran\DhiranPayment::methodLabel($entry->payment_method) }}</td>
                                <td data-label="Description" data-low="1">{{ $entry->description ?? '-' }}</td>
                                <td data-label="Running Balance" class="whitespace-nowrap text-right font-medium {{ $runningBalance >= 0 ? '' : 'text-red-600' }}" data-running-balance>{{ $showRunning ? \App\Support\IndianNumber::format($runningBalance, 2) : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="jf-table-empty">
                                    <x-dhiran.empty icon="inbox" title="No cash entries found" text="No cash moved in the selected period. Widen the date range or clear the filters.">
                                        <a class="btn btn-secondary btn-sm" href="{{ url()->current() }}">Clear filters</a>
                                    </x-dhiran.empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($entries->count())
                    <tfoot>
                        <tr>
                            <td data-low="1"></td>
                            <td colspan="3">Period totals ({{ $entries->total() }} entries)</td>
                            <td data-low="1"></td>
                            <td class="text-right">Net: ₹{{ \App\Support\IndianNumber::format($totals['net'], 2) }}</td>
                            <td></td>
                            <td data-low="1"></td>
                            <td class="text-right {{ $totals['closing'] >= 0 ? '' : 'text-red-600' }}">Closing: ₹{{ \App\Support\IndianNumber::format($totals['closing'], 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$entries" noun="entries" />
        </div>
    </div>
</x-dhiran-layout>
