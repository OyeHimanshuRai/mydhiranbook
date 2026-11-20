<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('terms_accepted_at')->nullable();
            $t->string('email_verify_otp')->nullable();
            $t->timestamp('email_otp_expires_at')->nullable();
            $t->unsignedSmallInteger('email_otp_attempts')->default(0);
        });
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary(); $t->string('token'); $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['terms_accepted_at', 'email_verify_otp', 'email_otp_expires_at', 'email_otp_attempts']));
    }
};
