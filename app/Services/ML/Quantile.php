<?php

namespace App\Services\ML;

/**
 * Kuantil dengan interpolasi linear (setara numpy.percentile default / R type 7).
 * Dipakai untuk menghitung Q10 / Q50 / Q90 dari sebaran prediksi antar-pohon.
 */
class Quantile
{
    /**
     * @param array<int, float> $values
     */
    public static function percentile(array $values, float $p): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        if ($p <= 0.0) {
            return min($values);
        }
        if ($p >= 1.0) {
            return max($values);
        }

        sort($values, SORT_NUMERIC);

        $pos = ($count - 1) * $p;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        if ($lo === $hi) {
            return $values[$lo];
        }

        $frac = $pos - $lo;

        return $values[$lo] * (1.0 - $frac) + $values[$hi] * $frac;
    }

    /**
     * @param array<int, float> $values
     * @return array{q10: float, q50: float, q90: float, low: float, high: float, width: float}
     */
    public static function interval(array $values, float $alpha = 0.10): array
    {
        $q10 = self::percentile($values, $alpha);
        $q50 = self::percentile($values, 0.50);
        $q90 = self::percentile($values, 1.0 - $alpha);

        return [
            'q10' => $q10,
            'q50' => $q50,
            'q90' => $q90,
            'low' => $q10,
            'high' => $q90,
            'width' => $q90 - $q10,
        ];
    }
}