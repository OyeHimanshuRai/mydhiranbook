{{-- Add / edit one staff member. $staff is set when editing. Same field names and rules as before; only layout, labels and wiring changed. --}}
@php
    $editing = isset($staff);
    $user = $editing ? $staff : null;
@endphp
<form method="POST" action="{{ $editing ? route('staff.update', $staff) : route('staff.store') }}" class="st-stack"
      x-data="{ dirty: {{ session()->hasOldInput() ? 'true' : 'false' }}, saving: false }" @input="dirty = true" @change="dirty = true" @submit="saving = true"
      @beforeunload.window="if (dirty && !saving) $event.preventDefault()">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="st-stack">
        <x-req-legend />
        <x-settings.panel title="Who they are" desc="Their name and the number they sign in with.">
            <div class="st-grid">
                <x-settings.field name="name" id="name" label="Full name" wide req required autocomplete="off" placeholder="Full name" :value="old('name', $user?->name)" />
                <x-settings.field name="mobile_number" id="mobile_number" label="Mobile number" type="tel" req inputmode="numeric" maxlength="10" pattern="[0-9]{10}" required autocomplete="off" prefix="+91"
                                  placeholder="10 digits" :value="old('mobile_number', $user?->mobile_number)" hint="Used for login" />
                <x-settings.field name="email" id="email" label="Email" type="email" autocomplete="off" placeholder="name@example.com" :value="old('email', $user?->email)" />
            </div>
        </x-settings.panel>

        <x-settings.panel title="Role" req desc="What this person can open and change. Edit a role's permissions under Roles & permissions.">
            <fieldset class="st-choice-set" @error('role_id') aria-describedby="role-err" @enderror>
                <legend class="st-sr">Role</legend>
                <div class="st-choices">
                    @foreach($roles as $role)
                        <label class="st-choice">
                            <input type="radio" name="role_id" value="{{ $role->id }}" required
                                   @checked($editing ? old('role_id', $staff->role_id) == $role->id : (old('role_id') == $role->id || ($loop->first && ! old('role_id'))))>
                            <span><b>{{ $role->display_name }}</b>@if($role->description)<small>{{ $role->description }}</small>@endif</span>
                        </label>
                    @endforeach
                </div>
                @error('role_id')<p class="st-error" id="role-err"><x-icon name="circle-alert" size="16" /><span>{{ $message }}</span></p>@enderror
            </fieldset>
        </x-settings.panel>

        <x-settings.panel :title="$editing ? 'Change password' : 'Password'" :desc="$editing ? 'Leave both fields empty to keep the current password.' : 'At least 6 characters. Share it with them in person.'">
            <div class="st-grid">
                <x-settings.field name="password" id="password" :label="$editing ? 'New password' : 'Password'" type="password" :req="! $editing" :required="! $editing" autocomplete="new-password" minlength="6" />
                <x-settings.field name="password_confirmation" id="password_confirmation" :label="$editing ? 'Confirm new password' : 'Confirm password'" type="password" :req="! $editing" :required="! $editing" autocomplete="new-password" />
            </div>
        </x-settings.panel>
    </div>

    <x-settings.savebar :label="$editing ? 'Save changes' : 'Add Staff Member'" :icon="$editing ? 'save' : 'user-plus'" :cancel-href="route('staff.index')" />
</form>
