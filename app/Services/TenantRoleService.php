<?php
namespace App\Services;
use App\Models\{Permission,Role};
use App\Support\TenantContext;
class TenantRoleService
{
    /** Provision independent Dhiran roles without resetting existing custom permissions. */
    public function ensureDefaultsForShop(int $shopId): array
    {
        return TenantContext::runFor($shopId,function () {
            $roles=[];
            foreach (['owner','manager','staff','cashier','operator','viewer'] as $name) {
                $role=Role::firstOrCreate(['name'=>$name],['display_name'=>ucfirst($name)]);
                if ($role->wasRecentlyCreated || $name === 'owner') {
                    $permissions=match($name) {
                        'owner'=>Permission::pluck('id'),
                        'manager'=>Permission::whereNotIn('name',['settings.edit','staff.manage'])->pluck('id'),
                        'staff','operator'=>Permission::whereIn('name',['dhiran.view','dhiran.create','dhiran.pay'])->pluck('id'),
                        'cashier'=>Permission::whereIn('name',['dhiran.view','dhiran.pay'])->pluck('id'),
                        default=>Permission::where('name','dhiran.view')->pluck('id'),
                    };
                    $role->permissions()->sync($permissions);
                }
                $roles[$name]=$role;
            }
            return $roles;
        });
    }
}
