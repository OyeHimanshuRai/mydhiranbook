<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    // Owner scope cut: announcements, impersonation and platform settings were never built.
    public function up(): void
    {
        foreach (['platform_impersonation_sessions','platform_announcements','platform_settings'] as $t) { Schema::dropIfExists($t); }
    }
    public function down(): void {} // no-op: the tables were unused; recreate from 2026_11_21_000000 if ever needed
};
