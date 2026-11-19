<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ArchivableParty;
use App\Models\Concerns\CanonicalisesMobileNumbers;
use App\Support\Mobile;
use Illuminate\Database\Eloquent\Model;
class Customer extends Model
{
    use BelongsToShop, ArchivableParty, CanonicalisesMobileNumbers;
    protected static array $mobileColumns=['mobile'];
    protected $fillable=['shop_id','first_name','last_name','mobile','email','address','pan','id_number','state_code','notes'];
    protected $casts=['is_active'=>'boolean'];
    public function getNameAttribute(): string { return trim($this->first_name.' '.$this->last_name); }
    public function shop() { return $this->belongsTo(Shop::class); }
    public function dhiranLoans() { return $this->hasMany(\App\Models\Dhiran\DhiranLoan::class); }
    protected static function booted(): void
    {
        static::creating(function(self $customer): void {
            if (empty($customer->customer_code) && !empty($customer->shop_id)) {
                $customer->customer_code=\App\Services\BusinessIdentifierService::nextCounter((int)$customer->shop_id,'customer');
            }
        });
    }
    public static function normalizeMobile(?string $mobile): ?string
    {
        return Mobile::normalize($mobile);
    }

    /**
     * What to actually store in `customers.mobile`.
     *
     * Canonical form, or EMPTY for anything that is not a mobile number. It used
     * to fall back to the raw string on the argument that dropping a walk-in's
     * only contact detail was worse than storing a number matching would not
     * recognise. That argument does not survive contact with the column: a value
     * stored there is indistinguishable from a real one, so "at least we kept it"
     * means a permanent unmatched row, a duplicate customer, and an SMS that
     * silently goes nowhere.
     *
     * Every form path now validates with IndianMobileRule, so this returns ''
     * only for the file-driven paths (CSV import), where the caller is expected
     * to skip the row rather than insert junk. Use it for the dedupe LOOKUP as
     * well as the insert — normalising only one of the two turns a silent
     * duplicate into a unique-index 500.
     */
    public static function storableMobile(?string $mobile): string
    {
        return static::normalizeMobile($mobile) ?? '';
    }

    /**
     * The one way to look a customer up by mobile. Canonical first, legacy second.
     *
     * Canonicalising the write side alone would have been half a fix: rows
     * written before it still hold '+91 98123 00099', and an exact lookup for
     * '9812300099' cannot see them — so the same buyer gets a second record,
     * which is the very bug canonicalisation was meant to end.
     *
     * The obvious fix is a backfill. We do not rewrite customer data, so this
     * closes the gap on the READ side only: the stored spelling is left exactly
     * as the shop typed it, forever, and the lookup is taught to see through it.
     * Nothing here writes.
     *
     * ponytail: the fallback is a per-shop scan of non-canonical rows only, and
     * it is permanent rather than draining, because nothing repairs the rows it
     * finds. That is the deliberate trade for not touching stored data. If it
     * ever shows up in a profile, the upgrade is a STORED generated column on
     * last-10-digits plus an index — which is derived data, not a rewrite of
     * anything the shop typed.
     */
    public static function resolveByMobile(?string $mobile): ?self
    {
        $canonical = static::normalizeMobile($mobile);

        // Deliberately NOT storableMobile(): storing is strict now (a value that
        // is not a mobile number stores as nothing), but LOOKING UP has to stay
        // lenient, or a legacy row holding a nine-digit scrap becomes permanently
        // unreachable — searchable only by a value the search refuses to accept.
        // Reads do not create bad data; refusing to read it only hides it.
        $needle = $canonical ?? trim((string) $mobile);

        if ($needle === '') {
            return null;
        }

        // Index hit. Every row written since canonicalisation lands here, as
        // does every legacy row that was already stored clean.
        $exact = static::query()->where('mobile', $needle)->first();
        if ($exact || $canonical === null) {
            // No canonical form means there is nothing to match loosely against
            // — an unparseable mobile is only ever equal to itself.
            return $exact;
        }

        // Only rows that cannot already be canonical are worth comparing. A
        // stored value of exactly ten characters either IS the canonical form
        // (found above) or has too few digits to normalise to anything, so
        // skipping it is correctness, not just an optimisation.
        return static::query()
            ->whereRaw('LENGTH(mobile) <> 10')
            ->get()
            ->first(fn (self $c): bool => static::normalizeMobile($c->mobile) === $canonical);
    }

}
