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
        'advance_interest_enabled',
        'advance_interest_default_months',
        'loan_number_digits',
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
        'hidden_receipt_fields',
    ];

    protected $casts = [
        'is_enabled'                    => 'boolean',
        'advance_interest_enabled'      => 'boolean',
        'advance_interest_default_months' => 'integer',
        'loan_number_digits'            => 'integer',
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
        'hidden_receipt_fields'         => 'array',
    ];

    /**
     * Receipt fields the owner may switch off, per receipt, grouped for the settings page. This is the single source
     * for the settings form, the save validation and the receipt views. The Loan Number is deliberately absent: it is
     * always printed (it is how a loan is found in the directory). Stored as hidden "receipt.key" strings, so a field
     * added here later shows by default.
     */
    public const RECEIPT_FIELDS = [
        'pledge' => [
            'label' => 'Pledge receipt',
            'groups' => [
                'Heading' => [
                    'shop_name' => 'Shop name', 'shop_address' => 'Shop address', 'shop_phone' => 'Shop phone',
                    'title' => 'Document title',
                ],
                'Loan' => [
                    'loan_date' => 'Date', 'principal_amount' => 'Principal amount', 'interest_rate' => 'Interest rate and type',
                    'tenure' => 'Tenure', 'maturity_date' => 'Maturity date',
                ],
                'Borrower' => [
                    'customer_name' => 'Name', 'customer_mobile' => 'Contact number', 'kyc_aadhaar' => 'Aadhaar', 'kyc_pan' => 'PAN',
                ],
                'Items table' => [
                    'item_description' => 'Description', 'item_huid' => 'HUID (under description)', 'item_metal' => 'Metal',
                    'item_gross' => 'Gross weight', 'item_net' => 'Net weight', 'item_purity' => 'Purity',
                    'item_fine' => 'Fine weight', 'item_value' => 'Value', 'item_totals' => 'Totals row',
                ],
                'Summary' => [
                    'summary_market_value' => 'Total market value', 'summary_ltv' => 'LTV applied',
                    'summary_principal' => 'Principal disbursed', 'summary_fee' => 'Processing fee',
                    'summary_advance' => 'Advance interest taken', 'summary_cash' => 'Cash handed to borrower',
                ],
                'Footer' => [
                    'terms' => 'Terms and conditions', 'signatures' => 'Signature lines', 'footer_note' => 'Computer-generated note',
                ],
            ],
        ],
        'payment' => [
            'label' => 'Payment receipt',
            'groups' => [
                'Heading' => [
                    'shop_name' => 'Shop name', 'shop_address' => 'Shop address', 'shop_contact' => 'Shop phone and email',
                    'title' => 'Document title',
                ],
                'Payment' => [
                    'receipt_no' => 'Receipt number', 'payment_for' => 'What the payment is for', 'payment_date' => 'Payment date and time', 'method' => 'Payment method',
                ],
                'Received from' => [
                    'customer_name' => 'Name', 'customer_mobile' => 'Contact number', 'customer_address' => 'Address',
                ],
                'Amount' => [
                    'principal_component' => 'Principal', 'interest_component' => 'Interest', 'penalty_component' => 'Penalty',
                    'fee_component' => 'Processing fee', 'amount_paid' => 'Amount paid',
                ],
                'Balance after payment' => [
                    'balance_principal' => 'Outstanding principal', 'balance_interest' => 'Outstanding interest',
                    'balance_penalty' => 'Outstanding penalty', 'balance_total' => 'Total balance',
                ],
                'Footer' => ['signatures' => 'Signature lines'],
            ],
        ],
        'notice' => [
            'label' => 'Forfeiture notice',
            'groups' => [
                'Heading' => [
                    'shop_name' => 'Shop name', 'shop_address' => 'Shop address', 'shop_contact' => 'Shop phone and email',
                    'shop_gstin' => 'Shop GSTIN', 'title' => 'Document title', 'notice_date' => 'Date of the notice',
                ],
                'Parties' => ['from' => 'From (the shop)', 'to_name' => 'Borrower name', 'to_contact' => 'Borrower phone and address'],
                'Loan' => ['loan_date' => 'Loan date', 'maturity_date' => 'Maturity date', 'days_overdue' => 'Days overdue'],
                'Amount due' => ['principal_due' => 'Outstanding principal', 'interest_due' => 'Outstanding interest', 'penalty_due' => 'Outstanding penalty', 'total_due' => 'Total outstanding'],
                'Letter' => ['letter' => 'The letter', 'warning' => 'The warning with the deadline'],
                'Footer' => ['signatures' => 'Signature lines'],
            ],
        ],
    ];

    /** Every switchable "receipt.key" string. */
    public static function receiptKeys(): array
    {
        $keys = [];
        foreach (self::RECEIPT_FIELDS as $receipt => $def) {
            foreach ($def['groups'] as $fields) {
                foreach (array_keys($fields) as $key) {
                    $keys[] = "$receipt.$key";
                }
            }
        }

        return $keys;
    }

    /** `$show('key')`: is this field printed on the given receipt for this shop? */
    public static function receiptVisibility(int $shopId, string $receipt): \Closure
    {
        $hidden = static::getForShop($shopId)->hidden_receipt_fields ?? [];

        return fn (string $key): bool => ! in_array("$receipt.$key", $hidden, true);
    }

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
