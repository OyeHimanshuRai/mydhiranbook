<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name')->unique();$t->string('display_name');$t->string('group');$t->text('description')->nullable();$t->timestamps();});
        Schema::create('roles',function(Blueprint $t){$t->id();$t->foreignId('shop_id')->constrained()->restrictOnDelete();$t->string('name');$t->string('display_name');$t->text('description')->nullable();$t->timestamps();$t->unique(['shop_id','name']);});
        Schema::create('role_permission',function(Blueprint $t){$t->foreignId('role_id')->constrained()->cascadeOnDelete();$t->foreignId('permission_id')->constrained()->cascadeOnDelete();$t->timestamps();$t->primary(['role_id','permission_id']);});
        Schema::table('users',function(Blueprint $t){$t->string('email')->nullable()->change();$t->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();$t->string('mobile_number',20)->nullable()->unique();$t->string('realm')->default('dhiran');$t->string('first_name')->nullable();$t->string('last_name')->nullable();$t->timestamp('email_verified_at')->nullable();$t->string('employment_status')->default('active');$t->timestamp('terminated_at')->nullable();$t->bigInteger('terminated_by_user_id')->nullable();$t->string('terminated_with_role_name')->nullable();});
        Schema::table('customers',function(Blueprint $t){$t->string('last_name')->nullable()->change();$t->string('customer_code')->nullable();$t->string('email')->nullable();$t->text('notes')->nullable();});
        Schema::create('audit_logs',function(Blueprint $t){$t->id();$t->foreignId('shop_id')->constrained()->restrictOnDelete();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('action');$t->string('model_type')->nullable();$t->bigInteger('model_id')->nullable();$t->text('description')->nullable();foreach(['data','actor','target','before','after'] as $c){$t->jsonb($c)->nullable();}$t->string('ip',45)->nullable();$t->text('user_agent')->nullable();$t->timestamps();});
        foreach(['dhiran.view','dhiran.create','dhiran.pay','dhiran.release','dhiran.renew','dhiran.forfeit','dhiran.settings','dhiran.reports','users.view','users.create','users.edit','users.delete'] as $name){DB::table('permissions')->insert(['name'=>$name,'display_name'=>$name,'group'=>str_starts_with($name,'dhiran')?'dhiran':'users','created_at'=>now(),'updated_at'=>now()]);}
        foreach(DB::table('shops')->pluck('id') as $shop){
            foreach(['owner','manager','staff','cashier','operator','viewer'] as $name){
                $id=DB::table('roles')->insertGetId(['shop_id'=>$shop,'name'=>$name,'display_name'=>ucfirst($name),'created_at'=>now(),'updated_at'=>now()]);
                $names=match($name){'owner'=>DB::table('permissions')->pluck('name')->all(),'manager'=>['dhiran.view','dhiran.create','dhiran.pay','dhiran.release','dhiran.renew','dhiran.reports'], 'staff','operator'=>['dhiran.view','dhiran.create','dhiran.pay'], 'cashier'=>['dhiran.view','dhiran.pay'], default=>['dhiran.view']};
                foreach(DB::table('permissions')->whereIn('name',$names)->pluck('id') as $permission){DB::table('role_permission')->insert(['role_id'=>$id,'permission_id'=>$permission,'created_at'=>now(),'updated_at'=>now()]);}
                DB::table('users')->where('shop_id',$shop)->where('role',$name)->update(['role_id'=>$id]);
            }
        }
    }
    public function down(): void { throw new RuntimeException('Restore the local checkpoint instead of discarding preserved staff records.'); }
};
