<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users',fn(Blueprint $t)=>$t->timestamp('reactivated_at')->nullable());
        foreach (['staff.view'=>'View staff','staff.manage'=>'Manage staff','settings.edit'=>'Manage role permissions'] as $name=>$label) {
            DB::table('permissions')->insertOrIgnore(['name'=>$name,'display_name'=>$label,'group'=>str_starts_with($name,'staff.')?'staff':'settings','created_at'=>now(),'updated_at'=>now()]);
        }
        foreach (DB::table('roles')->where('name','owner')->pluck('id') as $role) {
            foreach (DB::table('permissions')->pluck('id') as $permission) {
                DB::table('role_permission')->insertOrIgnore(['role_id'=>$role,'permission_id'=>$permission,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
    }
    public function down(): void { Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('reactivated_at')); }
};
