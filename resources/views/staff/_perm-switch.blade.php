{{-- One permission toggle row on the shared switch. $perm, $role, $label, $checked. --}}
<x-dhiran.switch :id="'perm-' . $role->id . '-' . $perm->id" name="permissions[]" :value="$perm->id" :label="$label" :checked="$checked" />
