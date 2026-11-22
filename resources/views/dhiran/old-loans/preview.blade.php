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

    <div class="content-inner">
        <div class="fm-page">
            <ol class="fm-flow" aria-label="The three steps">
                <li><span class="fm-num is-done" aria-hidden="true"><x-icon name="check" size="14" /></span><span class="fm-flow-name">Template<span class="fm-sr">, done</span></span></li>
                <li><span class="fm-num is-done" aria-hidden="true"><x-icon name="check" size="14" /></span><span class="fm-flow-name">Uploaded<span class="fm-sr">, done</span></span></li>
                <li aria-current="step"><span class="fm-num" aria-hidden="true">3</span><span class="fm-flow-name">Review</span></li>
            </ol>

            <div class="fm-tablecard">
                <table class="fm-table" aria-label="Loans in the file">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Ticket #</th>
                            <th scope="col">Rows</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Mobile</th>
                            <th scope="col" class="num">Lent</th>
                            <th scope="col" class="num">Owed now</th>
                            <th scope="col" class="num">Items</th>
                            <th scope="col">Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($loans as $loan)
                            @php $d = $loan['data']; @endphp
                            <tr data-ticket="{{ $loan['ticket'] }}" data-ok="{{ $loan['problems'] ? 0 : 1 }}">
                                <td data-label="#" class="fm-sno">{{ $loop->iteration }}</td>
                                <td data-label="Ticket #" class="fm-ticket">{{ $loan['ticket'] }}</td>
                                <td data-label="Rows">{{ min($loan['rows']) }}{{ count($loan['rows']) > 1 ? '–' . max($loan['rows']) : '' }}</td>
                                <td data-label="Customer">{{ trim(($d['borrower']['first_name'] ?? '') . ' ' . ($d['borrower']['last_name'] ?? '')) ?: '---' }}</td>
                                <td data-label="Mobile">{{ $d['borrower']['mobile'] ?? '---' }}</td>
                                <td data-label="Lent" class="num">{{ is_numeric($d['principal_amount']) ? '₹' . \App\Support\IndianNumber::format($d['principal_amount']) : '---' }}</td>
                                <td data-label="Owed now" class="num">{{ is_numeric($d['principal_owed']) ? '₹' . \App\Support\IndianNumber::format($d['principal_owed']) : '---' }}</td>
                                <td data-label="Items" class="num">{{ count($d['items']) }}</td>
                                <td data-label="Check" class="fm-check">
                                    @if($loan['problems'])
                                        <span class="fm-badge fm-badge--bad"><x-icon name="triangle-alert" size="14" /> Will be skipped</span>
                                        <ul class="fm-problems">
                                            @foreach($loan['problems'] as $problem)<li>{{ $problem }}</li>@endforeach
                                        </ul>
                                    @else
                                        <span class="fm-badge fm-badge--ok"><x-icon name="check" size="14" /> Ready</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="fm-bar" role="region" aria-label="Bring these loans in">
                <p class="fm-bar-text">
                    <strong>{{ $ready }} {{ $word }}</strong> will be brought in{!! $bad > 0 ? ', <strong>' . e($bad) . '</strong> skipped because of problems' : '' !!}.
                </p>
                <div class="fm-bar-acts">
                    @if($ready > 0)
                        <form method="POST" action="{{ route('dhiran.old-loans.upload.confirm', $token) }}" data-turbo-frame="_top"
                              x-data="{ busy: false }" @submit="busy = !$event.defaultPrevented" @pageshow.window="busy = false"
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
