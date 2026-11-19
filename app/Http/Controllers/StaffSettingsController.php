<?php
namespace App\Http\Controllers;
use App\Models\{Role,Permission,AuditLog};
use Illuminate\Http\Request;
class StaffSettingsController extends Controller
{
    public function index(Request $request)
    {
        $activeTab=$request->query('tab') === 'roles' ? 'roles' : 'staff';
        if ($activeTab === 'roles') { abort_unless($request->user()->can('settings.edit'),403); }
        $shop=$request->user()->shop;
        $roles=Role::with('permissions')->get();
        $permissionGroups=Permission::all()->groupBy('group');
        $staff=$shop->users()->with('assignedRole')->orderBy('id')->get();
        $staffLimit=$shop->staffLimit(); $staffCount=$shop->currentStaffCount();
        return view('staff.settings',compact('activeTab','roles','permissionGroups','staff','staffLimit','staffCount'));
    }
    public function updateRolePermissions(Request $request, Role $role)
    {
        // Owner role cannot be modified. This `if` is the ONLY guard — there is
        // no owner short-circuit in Gate::before, so the owner's all-access comes
        // solely from its synced permission set. Never remove this check.
        if ($role->name === 'owner') {
            return redirect()->route('settings.edit', ['tab' => 'roles'])
                ->with('error', 'Owner role permissions cannot be modified.');
        }

        $validated = $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        // Snapshot the current permission set so we can audit the diff.
        $beforeIds = $role->permissions()->pluck('permissions.id')->sort()->values()->all();

        $afterIds = collect($validated['permissions'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $role->permissions()->sync($afterIds);

        // Audit the change — added/removed permission names for accountability.
        $beforeSet = collect($beforeIds);
        $afterSet = collect($afterIds);
        $addedIds = $afterSet->diff($beforeSet)->all();
        $removedIds = $beforeSet->diff($afterSet)->all();
        $added = empty($addedIds) ? [] : \App\Models\Permission::whereIn('id', $addedIds)->pluck('name')->all();
        $removed = empty($removedIds) ? [] : \App\Models\Permission::whereIn('id', $removedIds)->pluck('name')->all();

        if (! empty($added) || ! empty($removed)) {
            AuditLog::create([
                'shop_id' => auth()->user()->shop_id,
                'user_id' => auth()->id(),
                'action' => 'role_permissions_updated',
                'model_type' => 'Role',
                'model_id' => $role->id,
                'description' => sprintf(
                    'Updated permissions for %s. Added: %s. Removed: %s.',
                    $role->display_name,
                    empty($added) ? 'none' : implode(', ', $added),
                    empty($removed) ? 'none' : implode(', ', $removed)
                ),
            ]);
        }

        return redirect()->route('settings.edit', ['tab' => 'roles'])
            ->with('success', "Permissions for {$role->display_name} updated successfully.");
    }

}
