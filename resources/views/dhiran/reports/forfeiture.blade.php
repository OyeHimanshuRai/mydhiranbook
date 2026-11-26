<x-dhiran-layout title="Forfeiture Report" list>
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Forfeiture Report</h1>
            <p class="text-sm text-gray-500 mt-1">Forfeited and written-off loans</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <x-dhiran.btn-collapse icon="printer" label="Print" onclick="window.print()" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-bar :filters="$filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request())" :action="route('dhiran.reports.forfeiture')" period-label="Forfeited in"
            :fields="['search', 'period', 'metal', 'amount']" />
        <turbo-frame id="dh-results" target="_top">
        {{-- Table --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Forfeited loan records">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Loan #</th>
                            <th scope="col">Customer</th>
                            <th scope="col" data-low="2">Mobile</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col" class="text-right">Written off</th>
                            <th scope="col" class="text-right" data-low="1">Items Count</th>
                            <th scope="col">Forfeiture Date</th>
                            <th scope="col" data-low="1">Notice sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans as $loan)
                            @php
                                $writtenOff = $loan->totalOutstanding();
                                $itemsCount = $loan->items_count;
                            @endphp
                            <tr>
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Loan #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-[var(--kh-ink-2)]">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="dh-clip font-medium text-slate-700" title="{{ $loan->customer?->name ?? '-' }}">{{ $loan->customer?->name ?? '-' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Principal" class="whitespace-nowrap text-right">{{ \App\Support\IndianNumber::rupee((float) $loan->principal_amount, 2) }}</td>
                                <td data-label="Written off" class="whitespace-nowrap text-right font-semibold">{{ \App\Support\IndianNumber::rupee($writtenOff, 2) }}</td>
                                <td data-label="Items Count" data-low="1" class="whitespace-nowrap text-right">{{ $itemsCount }}</td>
                                <td data-label="Forfeiture Date" class="whitespace-nowrap">{{ $loan->forfeited_at?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Notice sent" data-low="1" class="whitespace-nowrap">
                                    @if($loan->forfeiture_notice_sent_at)
                                        {{ $loan->forfeiture_notice_sent_at->format('d M Y') }}
                                    @else
                                        <span class="text-slate-400">Not sent</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="jf-table-empty">
                                    <x-dhiran.empty icon="inbox" title="No forfeited loans found" text="Nothing was forfeited in this period. Widen the date range or clear the filters.">
                                        <a class="btn btn-secondary btn-sm" href="{{ url()->current() }}">Clear filters</a>
                                    </x-dhiran.empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($loans->count())
                    <tfoot>
                        {{-- Every forfeited loan matching the filters, on every page (it used to total this page only). --}}
                        <tr>
                            <td data-low="1"></td>
                            <td colspan="2">Totals ({{ $totals['count'] }} loans)</td>
                            <td data-low="2"></td>
                            <td class="text-right" data-report-total="principal">{{ \App\Support\IndianNumber::rupee($totals['principal'], 2) }}</td>
                            <td class="text-right" data-report-total="outstanding">{{ \App\Support\IndianNumber::rupee($totals['outstanding'], 2) }}</td>
                            <td data-low="1" class="text-right">{{ $totals['items'] }}</td>
                            <td></td>
                            <td data-low="1"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="loans" />
        </div>
        </turbo-frame>
    </div>
</x-dhiran-layout>
