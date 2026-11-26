{{-- Loading placeholder: <x-dhiran.skeleton variant="table|cards|form|detail" :rows="8" />.
     Every placeholder is aria-hidden; one visually hidden status says "Loading". Each unit counts as one row (data-skel-row).
     resources/js/ux/skeleton.js builds the same markup for slow Turbo visits: keep the two in step. --}}
@props(['variant' => 'table', 'rows' => 8])
@php
    $variant = in_array($variant, ['table', 'cards', 'form', 'detail'], true) ? $variant : 'table';
    $rows = max(1, min(30, (int) $rows));
    $w = ['ux-w-35', 'ux-w-80', 'ux-w-50', 'ux-w-65'];
@endphp
<div class="ux-skel-wrap" data-skeleton-variant="{{ $variant }}">
    <span class="ux-sr" role="status">Loading</span>
    <div class="ux-skel-{{ $variant === 'table' ? 'card ux-skel-table' : ($variant === 'cards' ? 'cards' : $variant) }}" aria-hidden="true">
        @if($variant === 'table')
            <div class="ux-skel-head">@foreach($w as $c)<span class="ux-skel ux-skel-line ux-w-50"></span>@endforeach</div>
            @for($i = 0; $i < $rows; $i++)
                <div class="ux-skel-row" data-skel-row>@foreach($w as $c)<span class="ux-skel ux-skel-line {{ $c }}"></span>@endforeach</div>
            @endfor
        @elseif($variant === 'cards')
            @for($i = 0; $i < $rows; $i++)
                <div class="ux-skel-row ux-skel-card" data-skel-row>
                    <span class="ux-skel ux-skel-line ux-w-50"></span>
                    <span class="ux-skel ux-skel-line ux-w-80"></span>
                    <span class="ux-skel ux-skel-line ux-w-35"></span>
                </div>
            @endfor
        @elseif($variant === 'form')
            @for($i = 0; $i < $rows; $i++)
                <div class="ux-skel-row" data-skel-row>
                    <span class="ux-skel ux-skel-line ux-w-25"></span>
                    <span class="ux-skel ux-skel-box ux-w-100"></span>
                </div>
            @endfor
        @else
            <div class="ux-skel-title">
                <span class="ux-skel ux-skel-line ux-tall ux-w-35"></span>
                <span class="ux-skel ux-skel-line ux-w-50"></span>
            </div>
            @for($i = 0; $i < $rows; $i++)
                <div class="ux-skel-row ux-skel-card" data-skel-row>
                    <span class="ux-skel ux-skel-line ux-w-25"></span>
                    <span class="ux-skel ux-skel-line ux-w-80"></span>
                </div>
            @endfor
        @endif
    </div>
</div>
