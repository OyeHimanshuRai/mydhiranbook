<x-dhiran-layout title="Edit staff">
    @vite('resources/css/dhiran-settings.css')
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Edit Staff: {{ $staff->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">Member since {{ $staff->created_at->format('d M Y') }}</p>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Back to Staff" :href="route('staff.index')" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">
        <x-settings.live />
        <div class="st-page">@include('staff._form')</div>
    </div>
</x-dhiran-layout>
