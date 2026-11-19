<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('phone')->nullable(); $t->text('address')->nullable();
            $t->string('status')->default('active'); $t->date('financial_lock_date')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->foreignId('shop_id')->constrained()->restrictOnDelete(); $t->string('name'); $t->string('email')->unique();
            $t->string('password'); $t->string('role')->default('owner'); $t->boolean('is_active')->default(true); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id(); $t->foreignId('shop_id')->constrained()->restrictOnDelete(); $t->string('first_name'); $t->string('last_name')->default('');
            $t->string('mobile',20); $t->text('address')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); $t->unique(['shop_id','mobile']);
        });
        Schema::create('shop_counters', function (Blueprint $t) {
            $t->id(); $t->foreignId('shop_id')->constrained()->restrictOnDelete(); $t->string('counter_key'); $t->bigInteger('current_value')->default(0); $t->timestamps(); $t->unique(['shop_id','counter_key']);
        });
        Schema::create('payment_submissions', function (Blueprint $t) {
            $t->id(); $t->foreignId('shop_id')->constrained()->restrictOnDelete(); $t->uuid('submission_key'); $t->bigInteger('loan_id'); $t->bigInteger('payment_id'); $t->string('fingerprint',64); $t->unique(['shop_id','submission_key']);
        });
        Schema::create('jobs', function (Blueprint $t) { $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
        Schema::create('failed_jobs', function (Blueprint $t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password'); $t->string('role')->default('support');
            $t->jsonb('permissions')->default('[]'); $t->boolean('is_active')->default(true); $t->text('two_factor_secret')->nullable();
            $t->boolean('two_factor_enabled')->default(false); $t->bigInteger('last_totp_window')->default(-1); $t->jsonb('recovery_codes')->nullable();
            $t->timestamp('password_changed_at')->nullable(); $t->timestamp('last_login_at')->nullable(); $t->timestamp('email_verified_at')->nullable(); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('platform_password_reset_tokens', function (Blueprint $t) { $t->string('email')->primary(); $t->string('token'); $t->timestamp('created_at')->nullable(); });
        Schema::create('platform_audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('actor_admin_id')->nullable()->constrained('platform_admins')->restrictOnDelete(); $t->string('action'); $t->string('target_type'); $t->bigInteger('target_id')->nullable();
            $t->jsonb('before')->nullable(); $t->jsonb('after')->nullable(); $t->text('reason')->nullable(); $t->string('ip',45)->nullable(); $t->text('user_agent')->nullable(); $t->timestamp('created_at');
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id(); $t->foreignId('shop_id')->unique()->constrained()->restrictOnDelete(); $t->string('plan')->default('local-trial'); $t->string('status')->default('trial'); $t->date('expires_at'); $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['subscriptions','platform_audit_logs','platform_password_reset_tokens','platform_admins','failed_jobs','jobs','payment_submissions','shop_counters','customers','users','shops'] as $table) { Schema::dropIfExists($table); }
    }
};
