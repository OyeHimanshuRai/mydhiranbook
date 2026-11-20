<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64)->unique();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->decimal('price_monthly', 12, 2)->default(0);
            $t->decimal('price_yearly', 12, 2)->nullable();
            $t->integer('grace_days')->default(0);
            $t->boolean('is_active')->default(true);
            $t->jsonb('features')->nullable();
            $t->timestamps();
        });

        Schema::create('shop_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->nullable()->constrained('shops')->restrictOnDelete(); // null = paid before business exists
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $t->string('status', 24)->default('active');
            $t->string('billing_cycle', 10);
            $t->date('starts_at');
            $t->date('ends_at')->nullable();
            $t->date('grace_ends_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->decimal('price_paid', 10, 2)->nullable();
            $t->string('razorpay_order_id')->nullable()->unique();
            $t->string('razorpay_payment_id')->nullable()->unique();
            $t->foreignId('updated_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->string('actor_type', 20)->nullable(); // admin | self_service | system | webhook
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['shop_id', 'status']);
            $t->index(['shop_id', 'ends_at']);
        });
        DB::statement("ALTER TABLE shop_subscriptions ADD CONSTRAINT shop_subscriptions_status_check CHECK (status IN ('active','grace','expired','cancelled'))");
        DB::statement("ALTER TABLE shop_subscriptions ADD CONSTRAINT shop_subscriptions_cycle_check CHECK (billing_cycle IN ('monthly','yearly'))");

        Schema::create('subscription_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_subscription_id')->constrained('shop_subscriptions')->restrictOnDelete();
            $t->foreignId('shop_id')->nullable()->constrained('shops')->restrictOnDelete();
            $t->foreignId('admin_id')->nullable()->constrained('platform_admins')->restrictOnDelete();
            $t->string('event_type', 64);
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->text('reason')->nullable();
            $t->timestamps();
            $t->index(['shop_id', 'created_at']);
        });

        Schema::create('platform_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $t->foreignId('shop_subscription_id')->nullable()->constrained('shop_subscriptions')->nullOnDelete();
            $t->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $t->string('invoice_number', 50)->unique();
            $t->unsignedInteger('invoice_sequence');
            $t->string('billing_cycle', 10);
            $t->date('billing_period_start');
            $t->date('billing_period_end');
            $t->decimal('amount_before_tax', 14, 2);
            $t->decimal('gst_rate', 5, 2)->default(0);
            $t->decimal('gst_amount', 14, 2)->default(0);
            $t->decimal('total_amount', 14, 2);
            $t->string('razorpay_payment_id', 255)->nullable()->index();
            $t->string('razorpay_order_id', 255)->nullable();
            $t->string('payment_method', 30)->default('razorpay'); // razorpay | manual
            $t->string('status', 20)->default('issued');           // issued | cancelled
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancellation_reason', 500)->nullable();
            $t->timestamp('issued_at');
            $t->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['shop_id', 'issued_at']);
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('platform_announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title', 255);
            $t->text('body');
            $t->string('cta_label', 80)->nullable();
            $t->string('cta_url', 2048)->nullable();
            $t->string('type', 20)->default('info'); // info | warning | critical | banner
            $t->string('target', 20)->default('all'); // all | plan
            $t->string('target_value', 100)->nullable();
            $t->timestamp('publish_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('send_email')->default(false);
            $t->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamps();
            $t->index(['publish_at', 'expires_at']);
        });

        Schema::create('platform_impersonation_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_id')->constrained('platform_admins')->restrictOnDelete();
            $t->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('expires_at');
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['admin_id', 'ended_at']);
            $t->index(['shop_id', 'ended_at']);
            $t->index(['user_id', 'ended_at']);
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        foreach (['platform_impersonation_sessions', 'platform_announcements', 'platform_settings', 'platform_invoices', 'subscription_events', 'shop_subscriptions', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id(); $t->foreignId('shop_id')->unique()->constrained()->restrictOnDelete(); $t->string('plan')->default('local-trial'); $t->string('status')->default('trial'); $t->date('expires_at'); $t->timestamps();
        });
    }
};
