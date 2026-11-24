@php
    $ready = collect($loans)->filter(fn ($l) => ! $l['problems'])->count();
    $bad = count($loans) - $ready;
    $word = \Illuminate\Support\Str::plural('loan', $ready);
@endphp
<x-dhiran-layout title="Check Old Loans">
    @vite('resources/css/dhiran-forms.css')
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Check before bringing in</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $ready }} {{ $word }} ready · {{ $bad }} with problems · nothing is saved yet</p>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Upload another file" :href="route('dhiran.old-loans.upload')" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <div class="fm-page fm-page--wide">
            <ol class="fm-flow" aria-label="The three steps">
                <li><span class="fm-num is-done" aria-hidden="true"><x-icon name="check" size="14" /></span><span class="fm-flow-name">Template<span class="fm-sr">, done</span></span></li>
                <li><span class="fm-num is-done" aria-hidden="true"><x-icon name="check" size="14" /></span><span class="fm-flow-name">Uploaded<span class="fm-sr">, done</span></span></li>
                <li aria-current="step"><span class="fm-num" aria-hidden="true">3</span><span class="fm-flow-name">Review</span></li>
            </ol>

            <div class="dh-table-card" data-list-frame>
                <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Loans in the file">
                    <table class="jf-table dh-stack">
                        <thead>
                            <tr>
                                <th scope="col" class="dh-sno-th" data-low="1">#</th>
                                <th scope="col">Ticket #</th>
                                <th scope="col" data-low="1">Rows</th>
                                <th scope="col">Customer</th>
                                <th scope="col" data-low="2">Mobile</th>
                                <th scope="col" class="text-right">Lent</th>
                                <th scope="col" class="text-right">Owed now</th>
                                <th scope="col" class="text-center" data-low="1">Items</th>
                                <th scope="col">Check</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($loans as $loan)
                                @php $d = $loan['data']; @endphp
                                <tr data-ticket="{{ $loan['ticket'] }}" data-ok="{{ $loan['problems'] ? 0 : 1 }}">
                                    <td data-label="#" class="dh-sno" data-low="1">{{ $loop->iteration }}</td>
                                    <td data-label="Ticket #" data-cell="title" class="fm-ticket">{{ $loan['ticket'] }}</td>
                                    <td data-label="Rows" data-low="1">{{ min($loan['rows']) }}{{ count($loan['rows']) > 1 ? '–' . max($loan['rows']) : '' }}</td>
                                    <td data-label="Customer">{{ trim(($d['borrower']['first_name'] ?? '') . ' ' . ($d['borrower']['last_name'] ?? '')) ?: '---' }}</td>
                                    <td data-label="Mobile" data-low="2">{{ $d['borrower']['mobile'] ?? '---' }}</td>
                                    <td data-label="Lent" class="text-right">{{ is_numeric($d['principal_amount']) ? '₹' . \App\Support\IndianNumber::format($d['principal_amount']) : '---' }}</td>
                                    <td data-label="Owed now" class="text-right">{{ is_numeric($d['principal_owed']) ? '₹' . \App\Support\IndianNumber::format($d['principal_owed']) : '---' }}</td>
                                    <td data-label="Items" class="text-center" data-low="1">{{ count($d['items']) }}</td>
                                    <td data-label="Check">
                                        <div class="fm-check-body">
                                            @if($loan['problems'])
                                                <span class="dh-status dh-status--danger">Will be skipped</span>
                                                <ul class="fm-problems">
                                                    @foreach($loan['problems'] as $problem)<li>{{ $problem }}</li>@endforeach
                                                </ul>
                                            @else
                                                <span class="dh-status dh-status--success">Ready</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="fm-bar" role="region" aria-label="Bring these loans in">
                <p class="fm-bar-text">
                    <strong>{{ $ready }} {{ $word }}</strong> will be brought in{!! $bad > 0 ? ', <strong>' . e($bad) . '</strong> skipped because of problems' : '' !!}.
                </p>
                <div class="fm-bar-acts">
                    @if($ready > 0)
                        <form method="POST" action="{{ route('dhiran.old-loans.upload.confirm', $token) }}" data-turbo-frame="_top"
                              x-data="{ busy: false }" @submit="busy = !$event.defaultPrevented" @pageshow.window="busy = false" @turbo:submit-end="if (!$event.detail.success) busy = false"
                              onsubmit="return confirm('Bring in {{ $ready }} {{ $word }}? Loans with problems are skipped.');">
                            @csrf <x-dhiran.once />
                            <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy.toString()"><x-icon name="check" size="16" /> <span x-text="busy ? 'Bringing in…' : 'Bring in {{ $ready }} {{ $word }}'">Bring in {{ $ready }} {{ $word }}</span></button>
                        </form>
                    @else
                        <a href="{{ route('dhiran.old-loans.upload') }}" class="btn btn-primary">Upload a corrected file</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-dhiran-layout>
