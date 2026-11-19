<?php

namespace App\Models\Dhiran;

use App\Models\Concerns\BelongsToShop;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;

class DhiranSettings extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'is_enabled',
        'default_interest_rate_monthly',
        'default_interest_type',
        'default_penalty_rate_monthly',
        'default_ltv_ratio',
        'high_value_ltv_ratio',
        'high_value_threshold',
        'default_tenure_months',
        'default_min_lock_months',
        'default_min_interest_months',
        'min_loan_amount',
        'max_loan_amount',
        'processing_fee_type',
        'processing_fee_value',
        'grace_period_days',
        'forfeiture_notice_days',
        'loan_number_prefix',
        'kyc_mandatory',
        'receipt_header_text',
        'receipt_footer_text',
        'receipt_terms_text',
        'closure_certificate_text',
        'sms_reminders_enabled',
        'reminder_days_before_due',
    ];

    protected $casts = [
        'is_enabled'                    => 'boolean',
        'kyc_mandatory'                 => 'boolean',
        'sms_reminders_enabled'         => 'boolean',
        'default_interest_rate_monthly' => 'decimal:2',
        'default_penalty_rate_monthly'  => 'decimal:2',
        'default_ltv_ratio'             => 'decimal:2',
        'high_value_ltv_ratio'          => 'decimal:2',
        'high_value_threshold'          => 'decimal:2',
        'min_loan_amount'               => 'decimal:2',
        'max_loan_amount'               => 'decimal:2',
        'processing_fee_value'          => 'decimal:2',
        'default_tenure_months'         => 'integer',
        'default_min_lock_months'       => 'integer',
        'default_min_interest_months'   => 'integer',
        'grace_period_days'             => 'integer',
        'forfeiture_notice_days'        => 'integer',
        'reminder_days_before_due'      => 'integer',
    ];

    /* ── Relationships ─────────────────────────────────── */

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /* ── Static helpers ────────────────────────────────── */

    public static function getForShop(int $shopId): self
    {
        return static::withoutGlobalScope('shop')
            ->where('shop_id', $shopId)
            ->firstOrCreate(
                ['shop_id' => $shopId],
                [
                    'is_enabled'                    => false,
                    'default_interest_rate_monthly' => 2.00,
                    'default_interest_type'         => 'flat',
                    'default_penalty_rate_monthly'  => 0.50,
                    'default_ltv_ratio'             => 75.00,
                    'high_value_ltv_ratio'          => 75.00,
                    'high_value_threshold'          => 250000.00,
                    'default_tenure_months'         => 12,
                    'default_min_lock_months'       => 0,
                    'default_min_interest_months'   => 1,
                    'min_loan_amount'               => 1000.00,
                    'max_loan_amount'               => 5000000.00,
                    'processing_fee_type'           => 'flat',
                    'processing_fee_value'          => 0,
                    'grace_period_days'             => 30,
                    'forfeiture_notice_days'        => 30,
                    'loan_number_prefix'            => 'DH-',
                    'kyc_mandatory'                 => true,
                    'sms_reminders_enabled'         => false,
                    'reminder_days_before_due'      => 7,
                ]
            );
    }
}
