<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Command palette search: this shop's loans and customers, 6 of each at most, as [{type, label, sub, url}]. */
class DhiranPaletteController extends Controller
{
    private const LIMIT = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->query('q');
        // Strings of 2 to 60 characters, valid UTF-8, no control characters (a NUL byte would be a database error).
        if (! is_string($q) || ! mb_check_encoding($q, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $q)) {
            return $this->refuse();
        }
        $q = trim($q);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            return $this->refuse();
        }
        $like = '%' . addcslashes($q, '%_\\') . '%'; // % and _ are literal text, like DhiranListFilters::applySearch

        $byCustomer = fn ($c) => $c->where('first_name', 'ilike', $like)
            ->orWhere('last_name', 'ilike', $like)
            ->orWhere('mobile', 'ilike', $like)
            ->orWhereRaw("concat(first_name, ' ', last_name) ilike ?", [$like]);

        // The BelongsToShop scope on both models keeps every query to the signed-in shop.
        $loans = DhiranLoan::query()
            ->where(fn ($w) => $w->where('loan_number', 'ilike', $like)->orWhereHas('customer', $byCustomer))
            ->with('customer:id,first_name,last_name')
            ->latest('id')->limit(self::LIMIT)
            ->get(['id', 'customer_id', 'loan_number', 'status', 'principal_amount'])
            ->map(fn (DhiranLoan $l) => [
                'type' => 'loan',
                'label' => (string) $l->loan_number,
                'sub' => implode(' · ', array_filter([$l->customer?->name, ucfirst(str_replace('_', ' ', (string) $l->status)), '₹' . number_format((float) $l->principal_amount)])),
                'url' => route('dhiran.show', $l, false),
            ]);

        $customers = Customer::query()
            ->where($byCustomer)
            ->orderBy('first_name')->orderBy('last_name')->limit(self::LIMIT)
            ->get(['id', 'first_name', 'last_name', 'mobile'])
            ->map(fn (Customer $c) => [
                'type' => 'customer',
                'label' => $c->name,
                'sub' => (string) $c->mobile,
                'url' => route('dhiran.borrowers.show', $c, false),
            ]);

        return response()->json($loans->concat($customers)->values())->header('Cache-Control', 'no-store, private');
    }

    private function refuse(): JsonResponse
    {
        return response()->json(['message' => 'Type 2 to 60 characters to search.'], 422)->header('Cache-Control', 'no-store, private');
    }
}
