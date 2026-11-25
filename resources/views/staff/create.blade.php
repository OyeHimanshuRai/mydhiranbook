<x-dhiran-layout title="Add Staff Member" settings>
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Add Staff Member</h1>
            <p class="text-sm text-gray-500 mt-1">Create a new employee account</p>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Back to Staff" :href="route('staff.index')" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">
        @php
            $staffLimit = $shop->staffLimit();
            $staffCount = $shop->currentStaffCount();
        @endphp
        <x-settings.live />
        <div class="st-page">
            <p class="st-notice">
                <x-icon name="info" size="20" />
                <span>Staff accounts: <strong>{{ $staffCount }}{{ $staffLimit !== -1 ? ' of ' . $staffLimit : '' }}</strong> used.
                    @if($staffLimit !== -1){{ $staffLimit - $staffCount }} slot(s) remaining after this one.@else Unlimited.@endif</span>
            </p>
            @include('staff._form')
        </div>
    </div>
</x-dhiran-layout>
