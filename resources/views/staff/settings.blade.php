<x-dhiran-layout title="Users & roles">
<x-dhiran.page-header><div><h1 class="page-title">Users & roles</h1></div><div class="page-actions"><a class="btn btn-secondary btn-sm" href="{{ route('settings.edit',['tab'=>'staff']) }}">Staff</a>@can('settings.edit')<a class="btn btn-secondary btn-sm" href="{{ route('settings.edit',['tab'=>'roles']) }}">Roles & Permissions</a>@endcan</div></x-dhiran.page-header>
<div class="content-inner"><x-dhiran.settings-nav active="users" /><div class="settings-shell">            @if($activeTab === 'roles')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Roles & Permissions') }}</h2>
                    <p class="settings-desc">{{ __('Configure access levels for each role') }}</p>
                </div>
                
                <div class="roles-container">
                    @foreach($roles as $role)
                        <div class="role-card {{ $role->name === 'owner' ? 'locked' : '' }}">
                            <div class="role-head">
                                <h4 class="role-title">{{ __($role->display_name) }}</h4>
                                <span class="role-badge">{{ $role->permissions->count() }} {{ __('permissions') }}</span>
                                @if($role->name === 'owner')
                                    <span class="locked-msg role-badge-spacer">{{ __('All permissions — locked') }}</span>
                                @endif
                            </div>

                            @if($role->name !== 'owner')
                                <form method="POST" action="{{ route('settings.update.role', $role) }}" data-turbo-frame="_top">
                                    @csrf
                                    @method('PATCH')

                                    <div class="role-body">
                                        @foreach($permissionGroups as $group => $groupPerms)
                                            {{-- The DB group key is 'dhiran'. --}}
                                            <div class="perm-group">
                                                <div class="perm-group-title">{{ __($group) }}</div>
                                                @foreach($groupPerms as $perm)
                                                    <label class="perm-item">
                                                        <input type="checkbox" name="permissions[]" value="{{ $perm->id }}"
                                                            class="settings-toggle-input-md"
                                                            {{ $role->permissions->contains($perm->id) ? 'checked' : '' }}>
                                                        <span class="perm-item-label">{{ __($perm->display_name) }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="role-foot">
                                        <button type="submit" class="role-save-btn">{{ __('Save') }} {{ __($role->display_name) }}</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if($activeTab === 'staff')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Staff Members') }}</h2>
                    <p class="settings-desc">{{ __('Manage your shop\'s employees and their access') }}</p>
                </div>

                @php
                    $atLimit  = $staffLimit !== -1 && $staffCount >= $staffLimit;
                    $pct      = $staffLimit > 0 ? min(100, round($staffCount / $staffLimit * 100)) : 0;
                    $barColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#0d9488');
                @endphp

                {{-- Limit bar --}}
                <div class="settings-staff-summary mb-4 flex items-center gap-4 p-3 bg-white border border-gray-200 rounded-lg text-sm">
                    <span class="text-gray-600 font-medium whitespace-nowrap">{{ __('Staff accounts:') }}</span>
                    @if($staffLimit === -1)
                        <span class="text-gray-700">{{ $staffCount }} {{ __('used') }} &nbsp;<span class="text-green-600 font-semibold">· {{ __('Unlimited') }}</span></span>
                    @else
                        <div class="flex-1 flex items-center gap-3">
                            <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                                <div style="width:{{ $pct }}%; background:{{ $barColor }};" class="h-full rounded-full transition-all"></div>
                            </div>
                            <span class="whitespace-nowrap font-semibold {{ $atLimit ? 'text-red-600' : 'text-gray-700' }}">
                                {{ $staffCount }} / {{ $staffLimit }}
                                @if($atLimit) — {{ __('Limit reached') }} @endif
                            </span>
                        </div>
                    @endif
                    @can('staff.manage')
                    <div class="ml-auto">
                        @if($atLimit)
                            <span class="btn btn-secondary btn-sm opacity-50 cursor-not-allowed">+ {{ __('Add Staff') }}</span>
                        @else
                            <a href="{{ route('staff.create') }}" class="btn btn-primary btn-sm" data-turbo-frame="_top">+ {{ __('Add Staff') }}</a>
                        @endif
                    </div>
                    @endcan
                </div>

                {{-- Staff cards --}}
                <div class="settings-record-grid grid grid-cols-1 md:grid-cols-2 gap-4">
                    @forelse($staff as $member)
                        <div class="settings-record-card bg-white rounded-xl border border-gray-200 p-4">
                            <div class="settings-record-heading flex items-start justify-between">
                                <div class="settings-record-person flex items-center gap-3">
                                    <div class="settings-record-avatar h-10 w-10 rounded-full flex items-center justify-center font-bold">
                                        {{ strtoupper(substr($member->name ?? $member->mobile_number, 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900 text-sm">{{ $member->name ?? \App\Support\Mobile::forDisplay($member->mobile_number) }}</p>
                                        @if($member->name)
                                            <p class="text-xs text-gray-500">{{ \App\Support\Mobile::forDisplay($member->mobile_number) }}</p>
                                        @endif
                                        @if($member->email)
                                            <p class="text-xs text-gray-400">{{ $member->email }}</p>
                                        @endif
                                    </div>
                                </div>
                                @php
                                    $roleName = $member->assignedRole?->name ?? 'staff';
                                    $roleColors = ['owner' => 'bg-purple-100 text-purple-800', 'manager' => 'bg-blue-100 text-blue-800', 'staff' => 'bg-gray-100 text-gray-700'];
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $roleColors[$roleName] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ $member->assignedRole?->display_name ?? 'Staff' }}
                                </span>
                            </div>
                            <div class="settings-record-footer mt-3 pt-3 border-t border-gray-100 flex items-center justify-between">
                                @if(($member->employment_status ?? 'active') === 'terminated')
                                    <span class="text-xs text-rose-500">{{ __('Removed') }} {{ optional($member->terminated_at)->format('d M Y') }}</span>
                                @else
                                    <span class="text-xs text-gray-400">{{ __('Joined') }} {{ $member->created_at->format('d M Y') }}</span>
                                @endif
                                @php
                                    $isOwnerRow = $member->assignedRole?->name === 'owner';
                                    $isSelfRow  = $member->id === auth()->id();
                                    $isTerminated = ($member->employment_status ?? 'active') === 'terminated';
                                @endphp
                                @if($isSelfRow)
                                    <span class="text-xs text-gray-400 italic">{{ __('You') }}</span>
                                @elseif($isOwnerRow)
                                    {{-- The owner account is managed only via Profile, never from staff management. --}}
                                    <span class="text-xs text-gray-400 italic">{{ __('Owner') }}</span>
                                @else
                                    {{-- Edit / Remove / Recover require staff.manage; the owner-only default
                                         means a view-only manager sees no action buttons here. --}}
                                    @can('staff.manage')
                                        @if($isTerminated)
                                            {{-- Recovery: restore a previously-removed staff member. --}}
                                            <form method="POST" action="{{ route('staff.reactivate', $member) }}" data-turbo-frame="_top"
                                                  data-confirm-message="{{ __('Recover :name?', ['name' => $member->name ?? $member->mobile_number]) }}">
                                                @csrf @method('PATCH')
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-emerald-100 text-emerald-700 rounded hover:bg-emerald-200">
                                                    <x-icon name="rotate-ccw" size="11" />
                                                    {{ __('Recover') }}
                                                </button>
                                            </form>
                                        @else
                                            <div class="flex gap-2">
                                                <a href="{{ route('staff.edit', $member) }}" data-turbo-frame="_top"
                                                   class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200">
                                                    <x-icon name="square-pen" size="11" />
                                                    {{ __('Edit') }}
                                                </a>
                                                <form method="POST" action="{{ route('staff.destroy', $member) }}" data-turbo-frame="_top"
                                                      data-confirm-message="{{ __('Remove :name? They can be recovered later.', ['name' => $member->name ?? $member->mobile_number]) }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200">
                                                        <x-icon name="trash-2" size="11" />
                                                        {{ __('Remove') }}
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    @endcan
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="settings-empty-state col-span-full text-center py-10 text-gray-400">
                            <p class="mb-3">{{ __('No staff members yet') }}</p>
                            @if(!$atLimit)
                                <a href="{{ route('staff.create') }}" class="btn btn-primary btn-sm" data-turbo-frame="_top">+ {{ __('Add Staff') }}</a>
                            @endif
                        </div>
                    @endforelse
                </div>
            @endif

</div></div></x-dhiran-layout>
