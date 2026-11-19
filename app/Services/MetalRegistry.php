<?php
namespace App\Services;
final class MetalRegistry
{
    public static function fineWeightMultiplier(string $metal, float $purity): ?float
    {
        $normalized = strtolower(trim($metal));


        if (! in_array($normalized, ['gold', 'silver'], true)) {
            return null;
        }

        return match ($normalized) {
            'gold'   => $purity / 24.0,
            'silver' => $purity / 1000.0,
            default  => null,
        };
    }

}
