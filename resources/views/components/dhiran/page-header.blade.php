@props([
    'title' => null,
    'subtitle' => null,
])

@php
    $slotMarkup = trim((string) $slot);
    // Pledge-loan views pass their title/actions as slot children (legacy style).
    $useSlotLayout = $title === null && $subtitle === null && $slotMarkup !== '';
    $hasHeaderActions = ($useSlotLayout && str_contains($slotMarkup, 'page-actions')) || isset($actions);
@endphp

{{-- Dhiran-only page header. Pushed to the layout's header band, above the scroll area, so the page scrollbar
     starts under the header; nothing renders where the page wrote <x-dhiran.page-header>. --}}
@push('dh-page-header')
<div {{ $attributes->merge(['class' => 'content-header dh-page-header', 'data-legacy-header-normalized' => 'true']) }}
     x-data="{
        dhHeaderActionsOpen: false,
        dhHeaderHasFab: false,
        dhHeaderSource: null,
        dhHeaderFab: null,
        dhHeaderFabNav: null,
        dhHeaderOriginalActions: [],
        dhHeaderMedia: null,
        initDhHeaderActions() {
            this.dhHeaderSource = this.$el.querySelector('.dh-page-header-content > .page-actions');
            this.dhHeaderFab = this.$el.querySelector('.dh-header-mobile-fab');
            this.dhHeaderFabNav = this.dhHeaderFab ? this.dhHeaderFab.querySelector('[data-dh-header-fab-nav]') : null;
            if (!this.dhHeaderSource || !this.dhHeaderFabNav) return;

            this.dhHeaderOriginalActions = Array.from(this.dhHeaderSource.children);
            this.dhHeaderMedia = window.matchMedia('(max-width: 640px)');
            this.dhSyncHeaderActions();

            const onChange = () => this.dhSyncHeaderActions();
            if (this.dhHeaderMedia.addEventListener) {
                this.dhHeaderMedia.addEventListener('change', onChange);
            } else {
                this.dhHeaderMedia.addListener(onChange);
            }
        },
        dhIsPinnedHeaderAction(el) {
            const label = (el.textContent || '').trim().replace(/\s+/g, ' ').toLowerCase();
            return el.dataset.dhMobilePin === 'true' || el.classList.contains('btn-primary') || label.startsWith('back') || label === 'dashboard' || label === 'all loans';
        },
        dhPreparePinnedHeaderAction(el) {
            const label = (el.textContent || '').trim().replace(/\s+/g, ' ').toLowerCase();
            if (label.startsWith('back')) {
                el.dataset.dhMobileLabel = 'Back';
            } else {
                delete el.dataset.dhMobileLabel;
            }
        },
        dhSyncHeaderActions() {
            if (!this.dhHeaderSource || !this.dhHeaderFabNav || !this.dhHeaderMedia) return;

            this.dhHeaderActionsOpen = false;
            this.dhHeaderSource.classList.remove('dh-has-pinned-actions');

            // Phones fold every secondary action into the More menu; tablets only when the page has more than three (never a wrapped toolbar).
            const fold = this.dhHeaderMedia.matches || (window.matchMedia('(max-width: 1023.98px)').matches && this.dhHeaderOriginalActions.length > 3);
            if (!fold) {
                this.dhHeaderOriginalActions.forEach((el) => {
                    el.classList.remove('dh-header-pinned-action');
                    delete el.dataset.dhMobileLabel;
                    this.dhHeaderSource.appendChild(el);
                });
                this.dhHeaderHasFab = false;
                return;
            }

            let pinnedCount = 0;
            let fabCount = 0;
            this.dhHeaderOriginalActions.forEach((el) => {
                if (this.dhIsPinnedHeaderAction(el)) {
                    el.classList.add('dh-header-pinned-action');
                    this.dhPreparePinnedHeaderAction(el);
                    this.dhHeaderSource.appendChild(el);
                    pinnedCount += 1;
                } else {
                    el.classList.remove('dh-header-pinned-action');
                    delete el.dataset.dhMobileLabel;
                    this.dhHeaderFabNav.appendChild(el);
                    fabCount += 1;
                }
            });

            this.dhHeaderSource.classList.toggle('dh-has-pinned-actions', pinnedCount > 0);
            this.dhHeaderHasFab = fabCount > 0;
        },
        dhRestoreHeaderActions() {
            if (!this.dhHeaderSource || !this.dhHeaderOriginalActions.length) return;
            this.dhHeaderOriginalActions.forEach((el) => {
                el.classList.remove('dh-header-pinned-action');
                delete el.dataset.dhMobileLabel;
                this.dhHeaderSource.appendChild(el);
            });
        }
     }"
     x-init="initDhHeaderActions()"
     :class="{ 'dh-actions-open': dhHeaderActionsOpen }"
     @keydown.escape.window="dhHeaderActionsOpen = false"
     @resize.window.debounce.150ms="dhSyncHeaderActions()"
     @turbo:before-cache.window="dhRestoreHeaderActions()"
     @click.outside="if (!$event.target.closest('.dh-header-mobile-fab')) dhHeaderActionsOpen = false"
     x-on:click="if ($event.target.closest('[data-dh-header-fab-nav] a, [data-dh-header-fab-nav] button')) dhHeaderActionsOpen = false">
    <div class="dh-page-header-content">
        <button type="button" class="dh-header-toggle"
                onclick="window.dispatchEvent(new CustomEvent('dhiran-menu'))"
                aria-label="Open menu" aria-controls="dh-sidebar" aria-expanded="false">
            <x-icon name="menu" />
        </button>
        @if($useSlotLayout)
            {{ $slot }}
        @else
            <div class="min-w-0">
                <h1 class="page-title">{{ $title }}</h1>
                @if($subtitle)
                    <p class="page-subtitle">{{ $subtitle }}</p>
                @endif
            </div>

            @if(isset($actions))
                <div class="page-actions">
                    {{ $actions ?? '' }}
                </div>
            @endif
        @endif
        <x-dhiran.notice-bell />
    </div>

    @if($hasHeaderActions)
        <div class="dh-header-mobile-fab" x-show="dhHeaderHasFab" x-cloak>
            <div class="dh-header-mobile-fab-shell"
                 :class="{ 'is-open': dhHeaderActionsOpen }"
                 @click.outside="dhHeaderActionsOpen = false"
                 x-on:click="if ($event.target.closest('[data-dh-header-fab-nav] a, [data-dh-header-fab-nav] button')) dhHeaderActionsOpen = false">
                <nav class="dh-header-mobile-fab-nav" data-dh-header-fab-nav aria-label="Page actions"></nav>
                <button type="button" class="dh-header-mobile-fab-toggle"
                        x-on:click.stop="dhHeaderActionsOpen = !dhHeaderActionsOpen"
                        x-bind:aria-expanded="dhHeaderActionsOpen.toString()"
                        aria-label="More actions">
                    <svg x-show="!dhHeaderActionsOpen" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                    <x-icon name="x" x-show="dhHeaderActionsOpen" x-cloak />
                </button>
            </div>
        </div>
    @endif
</div>
@endpush
