<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Loans list sorts newest first (created_at, id) and the loan reports by loan_date, always inside one shop; the Customers list
// sorts by first name; the interest report filters payments by date. The shop is the leading column of each index (tenant scope).
// customers (shop_id, mobile) is already unique. Name/address search uses ILIKE '%text%', which a btree cannot serve.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $t) {
            $t->index(['shop_id', 'created_at', 'id'], 'dhiran_loans_shop_created_index');
            $t->index(['shop_id', 'loan_date', 'id'], 'dhiran_loans_shop_loan_date_index');
        });
        Schema::table('customers', fn (Blueprint $t) => $t->index(['shop_id', 'first_name', 'id'], 'customers_shop_name_index'));
        Schema::table('dhiran_payments', fn (Blueprint $t) => $t->index(['shop_id', 'payment_date'], 'dhiran_payments_shop_date_index'));
    }

    public function down(): void
    {
        Schema::table('dhiran_payments', fn (Blueprint $t) => $t->dropIndex('dhiran_payments_shop_date_index'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropIndex('customers_shop_name_index'));
        Schema::table('dhiran_loans', function (Blueprint $t) {
            $t->dropIndex('dhiran_loans_shop_loan_date_index');
            $t->dropIndex('dhiran_loans_shop_created_index');
        });
    }
};
