<x-dhiran-layout title="Users & roles" footer>
@vite('resources/css/dhiran-settings.css')
<x-dhiran.page-header>
    <x-dhiran.settings-nav active="users" as="title" />
</x-dhiran.page-header>
@php
    $myRoleId = auth()->user()->role_id;
    $totalPerms = $permissionGroups->flatten()->count();
    // ponytail: presentation-only mapping; the real fix is readable display_name values in the permissions table (a migration, not this view).
    // Seeded names like "dhiran.pay" read in plain words; a permission that already has a readable display name keeps it.
    $permLabel = function ($perm) {
        $words = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Remove', 'pay' => 'Record payments', 'renew' => 'Renew loans', 'release' => 'Release loans', 'forfeit' => 'Forfeit loans', 'settings' => 'Change loan settings', 'reports' => 'Open reports'];
        [$area, $verb] = array_pad(explode('.', $perm->display_name, 2), 2, null);
        if ($perm->display_name !== $perm->name || ! isset($words[$verb])) {
            return __($perm->display_name);
        }
        return __(in_array($verb, ['view', 'create', 'edit', 'delete'], true) ? $words[$verb] . ' ' . ($area === 'dhiran' ? 'loans' : $area) : $words[$verb]);
    };
    $firstEditable = $roles->first(fn ($r) => $r->name !== 'owner' && $r->id !== $myRoleId) ?? $roles->first();
@endphp
<div class="content-inner">
<x-dhiran.settings-nav active="users" switcher />
<x-settings.live />
<div class="st-page">
    <nav class="st-tabs" aria-label="Users and roles views">
        <a class="st-tab" href="{{ route('settings.edit', ['tab' => 'staff']) }}" @if($activeTab === 'staff') aria-current="page" @endif>Staff</a>
        @can('settings.edit')<a class="st-tab" href="{{ route('settings.edit', ['tab' => 'roles']) }}" @if($activeTab === 'roles') aria-current="page" @endif>Roles &amp; permissions</a>@endcan
    </nav>

    @if($activeTab === 'roles')
        <p class="st-lead">Choose a role, then switch on what people in that role may do. A change applies to everyone who holds the role.</p>

        <div class="st-stack" x-data="{
                 role: @js((string) $firstEditable?->id),
                 init() { try { const r = sessionStorage.getItem('st-role'); if (r && this.$el.querySelector('[data-role-pane=\'' + r + '\']')) this.role = r; } catch (e) {} },
                 remember(id) { try { sessionStorage.setItem('st-role', id); } catch (e) {} }
             }">
            <div class="st-roles" role="group" aria-label="Role to change">
                @foreach($roles as $role)
                    <button type="button" class="st-role-btn" aria-pressed="{{ $role->id === $firstEditable?->id ? 'true' : 'false' }}"
                            :aria-pressed="role === '{{ $role->id }}'" @click="role = '{{ $role->id }}'">
                        {{ __($role->display_name) }}
                        <small>{{ $role->name === 'owner' ? 'All permissions' : $role->permissions->count() . ' of ' . $totalPerms . ' on' }}</small>
                    </button>
                @endforeach
            </div>

            @foreach($roles as $role)
                @php
                    $isOwner = $role->name === 'owner';
                    $isMine = $role->id === $myRoleId;
                    $locked = $isOwner || $isMine; // own role: read-only, the server refuses the save
                    $granted = $role->permissions->pluck('id');
                @endphp
                <form method="POST" action="{{ route('settings.update.role', $role) }}" data-turbo-frame="_top" data-role-pane="{{ $role->id }}"
                      @unless($role->id === $firstEditable?->id) hidden @endunless :hidden="role !== '{{ $role->id }}'"
                      x-data="{ dirty: false, saving: false }" @input="dirty = true" @change="dirty = true" @submit="saving = true, remember('{{ $role->id }}')"
                      @beforeunload.window="if (dirty && !saving) $event.preventDefault()">
                    @csrf
                    @method('PATCH')
                    <div class="st-stack">
                        <x-settings.panel :title="__($role->display_name)" :desc="$isOwner ? 'The owner always has every permission.' : $role->permissions->count() . ' of ' . $totalPerms . ' permissions are on.'">
                            @if($isOwner)
                                <p class="st-notice"><x-icon name="lock" size="20" /><span>Owner permissions are locked so the shop can never be left without an administrator.</span></p>
                            @else
                                @if($isMine)
                                    <p class="st-notice"><x-icon name="lock" size="20" /><span>You hold this role, so its permissions are locked for you. Ask the owner to change them.</span></p>
                                @endif
                                @if($isMine)
                                    {{-- Read-only: only what the role already allows, as plain text. --}}
                                    <ul class="st-granted">@foreach($permissionGroups->flatten()->filter(fn ($p) => $granted->contains($p->id)) as $perm)<li>{{ $permLabel($perm) }}</li>@endforeach</ul>
                                @else
                                    <div class="st-perm-cols">
                                        @foreach($permissionGroups as $group => $groupPerms)
                                            <fieldset class="st-perm-group">
                                                {{-- The DB group key is 'dhiran'. --}}
                                                <legend>{{ __(['dhiran' => 'Loans'][$group] ?? ucfirst($group)) }}</legend>
                                                @foreach($groupPerms as $perm)
                                                    @include('staff._perm-switch', ['perm' => $perm, 'role' => $role, 'label' => $permLabel($perm), 'checked' => $granted->contains($perm->id)])
                                                @endforeach
                                            </fieldset>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </x-settings.panel>
                        @unless($locked)
                            <x-settings.savebar :label="'Save ' . __($role->display_name) . ' permissions'" />
                        @endunless
                    </div>
                </form>
            @endforeach
        </div>
    @endif

    @if($activeTab === 'staff')
        @php
            $atLimit = $staffLimit !== -1 && $staffCount >= $staffLimit;
            $pct = $staffLimit > 0 ? min(100, round($staffCount / $staffLimit * 100)) : 0;
        @endphp
        <p class="st-lead">{{ __('Manage your shop\'s employees and their access') }}.</p>

        <x-settings.panel title="Staff accounts" desc="Each person signs in with their own mobile number and password.">
            <div class="st-meter">
                <div class="st-meter-top">
                    <p class="st-meter-figure">
                        @if($staffLimit === -1)
                            <strong>{{ $staffCount }}</strong> {{ __('used') }} <span class="st-badge st-badge--ok">{{ __('Unlimited') }}</span>
                        @else
                            <strong>{{ $staffCount }} of {{ $staffLimit }}</strong> {{ __('used') }}
                            @if($atLimit) <span class="st-badge st-badge--bad">{{ __('Limit reached') }}</span> @endif
                        @endif
                    </p>
                    @can('staff.manage')
                        @if($atLimit)
                            <button type="button" class="btn btn-secondary btn-sm" disabled aria-describedby="st-limit-help"><x-icon name="plus" size="16" /> {{ __('Add Staff') }}</button>
                        @else
                            <a href="{{ route('staff.create') }}" class="btn btn-primary btn-sm" data-turbo-frame="_top"><x-icon name="plus" size="16" /> {{ __('Add Staff') }}</a>
                        @endif
                    @endcan
                </div>
                @if($staffLimit !== -1)
                    <div class="st-bar{{ $atLimit ? ' is-full' : ($pct >= 80 ? ' is-warn' : '') }}" role="img" aria-label="{{ $staffCount }} of {{ $staffLimit }} staff accounts used"><span style="--w: {{ $pct }}%"></span></div>
                @endif
                @if($atLimit)
                    <p class="st-hint" id="st-limit-help">Your plan allows {{ $staffLimit }} staff accounts. Remove a person you no longer need
                        @if(auth()->user()->isShopOwner()) or <a href="{{ route('subscription.status') }}">choose a larger plan</a>@endif.</p>
                @endif
            </div>
        </x-settings.panel>

        <section class="st-panel" aria-labelledby="st-members">
            <header class="st-panel-head"><h2 class="st-title" id="st-members">Staff members</h2></header>
            @if($staff->isEmpty())
                <div class="st-empty">
                    <p>{{ __('No staff members yet') }}</p>
                    @if(!$atLimit)<a href="{{ route('staff.create') }}" class="btn btn-primary btn-sm" data-turbo-frame="_top">+ {{ __('Add Staff') }}</a>@endif
                </div>
            @else
                <ul class="st-rows">
                    @foreach($staff as $member)
                        @php
                            $label = $member->name ?? \App\Support\Mobile::forDisplay($member->mobile_number);
                            $roleName = $member->assignedRole?->name ?? 'staff';
                            $isOwnerRow = $roleName === 'owner';
                            $isSelfRow = $member->id === auth()->id();
                            $isTerminated = ($member->employment_status ?? 'active') === 'terminated';
                        @endphp
                        <li class="st-row{{ $isTerminated ? ' is-removed' : '' }}">
                            <span class="st-avatar" aria-hidden="true">{{ strtoupper(substr($member->name ?? $member->mobile_number, 0, 1)) }}</span>
                            <div class="st-row-main">
                                <p class="st-row-name">{{ $label }}@if($isSelfRow) <span class="st-opt">({{ __('You') }})</span>@endif</p>
                                <span class="st-badge{{ in_array($roleName, ['owner', 'manager'], true) ? ' st-badge--' . $roleName : '' }}">{{ $member->assignedRole?->display_name ?? 'Staff' }}</span>
                                @if($isTerminated)<span class="st-badge st-badge--bad">{{ __('Removed') }} {{ optional($member->terminated_at)->format('d M Y') }}</span>@endif
                            </div>
                            <p class="st-row-meta">
                                @if($member->name && $member->mobile_number)<span>{{ \App\Support\Mobile::forDisplay($member->mobile_number) }}</span>@endif
                                @if($member->email)<span class="st-row-email">{{ $member->email }}</span>@endif
                                <span>{{ __('Joined') }} {{ $member->created_at->format('d M Y') }}</span>
                            </p>
                            @if(!$isSelfRow && !$isOwnerRow)
                                {{-- Edit / Remove / Recover require staff.manage; the owner-only default
                                     means a view-only manager sees no action buttons here. The owner account is managed only via Profile. --}}
                                @can('staff.manage')
                                    <div class="st-row-acts">
                                        @if($isTerminated)
                                            {{-- Recovery: restore a previously-removed staff member. --}}
                                            <form method="POST" action="{{ route('staff.reactivate', $member) }}" data-turbo-frame="_top"
                                                  data-confirm-message="{{ __('Recover :name?', ['name' => $member->name ?? $member->mobile_number]) }}">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="rotate-ccw" size="16" /> {{ __('Recover') }}</button>
                                            </form>
                                        @else
                                            <x-dhiran.btn-collapse icon="square-pen" :label="__('Edit')" :href="route('staff.edit', $member)" data-turbo-frame="_top" :aria-label="__('Edit') . ' ' . $label" />
                                            <form method="POST" action="{{ route('staff.destroy', $member) }}" data-turbo-frame="_top"
                                                  data-confirm-message="{{ __('Remove :name? They can be recovered later.', ['name' => $member->name ?? $member->mobile_number]) }}">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-secondary btn-sm st-danger" aria-label="{{ __('Remove') }} {{ $label }}"><x-icon name="trash-2" size="16" /> {{ __('Remove') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                @endcan
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
</div>
</x-dhiran-layout>
