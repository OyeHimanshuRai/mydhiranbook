<x-dhiran-layout title="Users & roles" settings footer>
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
    $firstEditable = $roles->first(fn ($r) => $r->name !== 'owner' && $r->id !== $myRoleId) ?? $roles->first();
    $requestedRole = (int) request()->query('role');
    // After a save the controller redirects to ?tab=roles with this exact flash: land on the role that was just saved.
    $savedRole = $roles->first(fn ($r) => session('success') === "Permissions for {$r->display_name} updated successfully.");
    $initialRole = ($roles->firstWhere('id', $requestedRole) ?? $savedRole ?? $firstEditable)?->id;
@endphp
<turbo-frame id="settings-pane" data-turbo-action="advance">
<div class="content-inner">
<x-dhiran.settings-nav active="users" switcher />
<x-settings.live />
<div class="st-page" x-data="{
         tab: @js($activeTab), role: @js((string) $initialRole),
         show(t) { this.tab = t; this.sync(); },
         pick(id) { this.role = id; this.sync(); },
         // The address always matches what is shown, so refresh and shared links land on the same tab and role. No request, no Turbo visit.
         sync() { const u = new URL(location.href); u.searchParams.set('tab', this.tab); this.tab === 'roles' ? u.searchParams.set('role', this.role) : u.searchParams.delete('role'); history.replaceState(history.state, '', u); },
         nav(e) {
             const tabs = [...e.currentTarget.querySelectorAll('[role=tab]')], i = tabs.indexOf(document.activeElement), n = tabs.length;
             const j = { ArrowRight: (i + 1) % n, ArrowDown: (i + 1) % n, ArrowLeft: (i - 1 + n) % n, ArrowUp: (i - 1 + n) % n, Home: 0, End: n - 1 }[e.key];
             if (i < 0 || j === undefined) return;
             e.preventDefault(); tabs[j].focus({ preventScroll: true }); tabs[j].click();
         }
     }">
    {{-- Without JavaScript (media query, not <noscript>, so a Turbo swap cannot show it) the tabs are plain links (the server renders the chosen tab); with it they are buttons that switch panels in place. --}}
    <nav class="st-tabs st-nojs" aria-label="Users and roles views">
            <a class="st-tab" href="{{ route('settings.edit', ['tab' => 'staff']) }}">Staff</a>
            @can('settings.edit')<a class="st-tab" href="{{ route('settings.edit', ['tab' => 'roles']) }}">Roles &amp; permissions</a>@endcan
        </nav>
    <div class="st-tabs st-tablist" role="tablist" aria-label="Users and roles" @keydown="nav($event)">
        <button type="button" class="st-tab" role="tab" id="tab-staff" aria-controls="pane-staff" aria-selected="{{ $activeTab === 'staff' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'staff' ? 0 : -1 }}"
                :aria-selected="tab === 'staff'" :tabindex="tab === 'staff' ? 0 : -1" @click="show('staff')">Staff</button>
        @can('settings.edit')
            <button type="button" class="st-tab" role="tab" id="tab-roles" aria-controls="pane-roles" aria-selected="{{ $activeTab === 'roles' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'roles' ? 0 : -1 }}"
                    :aria-selected="tab === 'roles'" :tabindex="tab === 'roles' ? 0 : -1" @click="show('roles')">Roles &amp; permissions</button>
        @endcan
    </div>

    @can('settings.edit')
    <section id="pane-roles" role="tabpanel" aria-labelledby="tab-roles" class="st-stack" @if($activeTab !== 'roles') hidden @endif :hidden="tab !== 'roles'">
        <p class="st-lead">Choose a role, then switch on what people in that role may do. A change applies to everyone who holds the role.</p>

        <div class="st-stack">
            <div class="st-roles" role="tablist" aria-label="Role to change" @keydown="nav($event)">
                @foreach($roles as $role)
                    <button type="button" class="st-role-btn" role="tab" id="role-tab-{{ $role->id }}" aria-controls="role-pane-{{ $role->id }}"
                            aria-selected="{{ $role->id === $initialRole ? 'true' : 'false' }}" tabindex="{{ $role->id === $initialRole ? 0 : -1 }}"
                            :aria-selected="role === '{{ $role->id }}'" :tabindex="role === '{{ $role->id }}' ? 0 : -1" @click="pick('{{ $role->id }}')">
                        {{ __($role->display_name) }}
                        <small>{{ $role->name === 'owner' ? 'All permissions' : $role->permissions->count() . ' of ' . $totalPerms . ' on' }}</small>
                    </button>
                @endforeach
            </div>

            <div class="st-formstack">
            @foreach($roles as $role)
                @php
                    $isOwner = $role->name === 'owner';
                    $isMine = $role->id === $myRoleId;
                    $locked = $isOwner || $isMine; // own role: read-only, the server refuses the save
                    $granted = $role->permissions->pluck('id');
                @endphp
                {{-- All role forms stay mounted in one grid cell (the cell is as tall as the tallest), so switching never moves the page and keeps unsaved toggles. --}}
                <form method="POST" action="{{ route('settings.update.role', $role) }}" data-turbo-frame="_top" data-role-pane="{{ $role->id }}"
                      id="role-pane-{{ $role->id }}" role="tabpanel" aria-labelledby="role-tab-{{ $role->id }}"
                      @if($role->id !== $initialRole) class="is-off" inert aria-hidden="true" @endif
                      :class="{ 'is-off': role !== '{{ $role->id }}' }" :inert="role !== '{{ $role->id }}'" :aria-hidden="role !== '{{ $role->id }}'"
                      x-data="stSaveForm()" @input="dirty = true" @change="dirty = true" @submit="submit($event)"
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
        </div>
    </section>
    @endcan

    <section id="pane-staff" role="tabpanel" aria-labelledby="tab-staff" class="st-stack" @if($activeTab !== 'staff') hidden @endif :hidden="tab !== 'staff'">
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
                    <p class="st-hint" id="st-limit-help">Your access allows {{ $staffLimit }} staff accounts. Remove a person you no longer need
                        @if(auth()->user()->isShopOwner()) or <a href="{{ route('subscription.status') }}">contact us to extend your access</a>@endif.</p>
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
    </section>
</div>
</div>
</turbo-frame>
</x-dhiran-layout>
