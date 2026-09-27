<?php

namespace App\Services\ML;

use RuntimeException;

/**
 * Implementasi Random Forest Regressor (murni PHP) sebagai dasar model tsQRF.
 *
 * - bootstrap sampling dengan pengembalian
 * - pencarian split terbaik (variance reduction / MSE) pada subset fitur acak
 * - max_features="sqrt" -> int(sqrt(n_fitur))
 * - prediksi memakai mean daun setiap pohon
 * - metode forecast: larik prediksi antar-pohon ($predictionsAllTrees) dipakai
 *   untuk menghitung kuantil Q10 / Q50 / Q90 (interval prediksi)
 *
 * Hyperparameter mengikuti panduan skripsi:
 *   RandomForestRegressor(n_estimators=500, max_depth=None,
 *                         min_samples_leaf=5, max_features="sqrt",
 *                         random_state=42, n_jobs=-1)
 */
class RandomForest
{
    public const FEATURE_NAMES = [
        'pembukaan', 'tertinggi', 'terendah', 'volume', 'perubahan', 'lag1', 'lag2', 'lag3',
    ];

    /** @var array<int, array> daftar pohon (node flat) */
    public array $trees = [];

    public int $nEstimators;
    public int $minSamplesLeaf;
    public int $minSamplesSplit;
    public string $maxFeaturesMode;
    public int $maxFeaturesCount = 0;
    public int $randomState;
    public int $nFeatures = 0;

    public function __construct(array $params = [])
    {
        $this->nEstimators = (int) ($params['n_estimators'] ?? 500);
        $this->minSamplesLeaf = max(1, (int) ($params['min_samples_leaf'] ?? 5));
        $this->minSamplesSplit = max(2, (int) ($params['min_samples_split'] ?? 2));
        $this->maxFeaturesMode = (string) ($params['max_features'] ?? 'sqrt');
        $this->randomState = (int) ($params['random_state'] ?? 42);

        if ($this->nEstimators < 10) {
            throw new RuntimeException('n_estimators minimal 10 pohon.');
        }
    }

    /**
     * @param array<int, array<int, float>> $X
     * @param array<int, float> $y
     */
    public function fit(array $X, array $y): void
    {
        $n = count($y);
        if ($n < 10) {
            throw new RuntimeException('Data latih terlalu sedikit (' . $n . ' baris).');
        }

        $this->nFeatures = count($X[0]);
        $this->maxFeaturesCount = $this->resolveMaxFeatures();

        $this->trees = [];

        for ($t = 0; $t < $this->nEstimators; $t++) {
            // seed deterministik per pohon
            mt_srand(($this->randomState * 2654435761) ^ ($t * 40503 + 17));

            $samples = [];
            for ($i = 0; $i < $n; $i++) {
                $samples[] = mt_rand(0, $n - 1);
            }

            $tree = [];
            $root = $this->buildNode($X, $y, $samples, $tree, 0);
            $tree['root'] = $root;
            $this->trees[] = $tree;

            unset($samples, $tree);
        }
    }

    private function resolveMaxFeatures(): int
    {
        $mode = strtolower(trim($this->maxFeaturesMode));
        if ($mode === 'sqrt') {
            return max(1, (int) floor(sqrt($this->nFeatures)));
        }
        if ($mode === 'all' || $mode === '1.0' || $mode === 'none') {
            return $this->nFeatures;
        }
        if (is_numeric($mode)) {
            if ((float) $mode >= 1.0) {
                return max(1, (int) round((float) $mode));
            }
            return max(1, (int) floor((float) $mode * $this->nFeatures));
        }

        return max(1, (int) floor(sqrt($this->nFeatures)));
    }

    /**
     * Bangun node (rekursif); node disimpan di $tree sebagai larik datar,
     * child dikenali lewat indeks. Daun menyimpan nilai mean.
     *
     * @return int indeks node yang dibuat
     */
    private function buildNode(array $X, array $y, array $samples, array &$tree, int $depth): int
    {
        $n = count($samples);

        // hitung mean & varian target di node
        $sum = 0.0;
        $sumSq = 0.0;
        foreach ($samples as $r) {
            $v = $y[$r];
            $sum += $v;
            $sumSq += $v * $v;
        }
        $mean = $sum / $n;
        $varTotal = $sumSq / $n - $mean * $mean;

        $idx = count($tree);
        $tree[] = ['value' => $mean];

        // syarat berhenti
        if ($n < 2 * $this->minSamplesLeaf || $varTotal <= 1e-12) {
            return $idx;
        }

        $best = $this->findBestSplit($X, $y, $samples, $sum, $sumSq, $varTotal);
        if ($best === null) {
            return $idx;
        }

        // pisahkan sampel
        $left = [];
        $right = [];
        $f = $best['feature'];
        $thr = $best['threshold'];
        foreach ($samples as $r) {
            if ($X[$r][$f] <= $thr) {
                $left[] = $r;
            } else {
                $right[] = $r;
            }
        }

        if (count($left) === 0 || count($right) === 0) {
            return $idx;
        }

        $leftIdx = $this->buildNode($X, $y, $left, $tree, $depth + 1);
        $rightIdx = $this->buildNode($X, $y, $right, $tree, $depth + 1);

        $tree[$idx] = [
            'value' => $mean,
            'feature' => $f,
            'threshold' => $thr,
            'left' => $leftIdx,
            'right' => $rightIdx,
        ];

        return $idx;
    }

    /**
     * Cari split terbaik pada subset fitur acak (variance reduction).
     *
     * @return array{feature:int, threshold:float, score:float}|null
     */
    private function findBestSplit(array $X, array $y, array $samples, float $total, float $totalSq, float $varTotal): ?array
    {
        $n = count($samples);

        // pilih fitur acak (tanpa pengulangan)
        $featurePool = [];
        for ($f = 0; $f < $this->nFeatures; $f++) {
            $featurePool[] = $f;
        }
        // shuffle ala mt (stream sudah di-seed per pohon)
        for ($i = count($featurePool) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$featurePool[$i], $featurePool[$j]] = [$featurePool[$j], $featurePool[$i]];
        }
        $candidateFeatures = array_slice($featurePool, 0, $this->maxFeaturesCount);

        $best = null;
        $bestScore = 0.0;

        foreach ($candidateFeatures as $f) {
            // kumpulkan nilai fitur + target, lalu sort berpasangan (lockstep, di C)
            $vals = [];
            $yt = [];
            foreach ($samples as $k => $r) {
                $vals[$k] = $X[$r][$f];
                $yt[$k] = $y[$r];
            }
            array_multisort($vals, SORT_ASC, SORT_NUMERIC, $yt);

            $left = 0.0;
            $leftSq = 0.0;
            $right = $total;
            $rightSq = $totalSq;

            $m = $n - 1;
            for ($k = 0; $k < $m; $k++) {
                $yv = $yt[$k];
                $left += $yv;
                $leftSq += $yv * $yv;
                $right -= $yv;
                $rightSq -= $yv * $yv;

                $lc = $k + 1;
                $rc = $n - $lc;

                if ($lc < $this->minSamplesLeaf || $rc < $this->minSamplesLeaf) {
                    continue;
                }
                if (abs($vals[$k] - $vals[$k + 1]) < 1e-12) {
                    continue; // bukan titik split (nilai sama)
                }

                $vL = $leftSq / $lc - ($left / $lc) * ($left / $lc);
                $vR = $rightSq / $rc - ($right / $rc) * ($right / $rc);
                $imp = $varTotal - ($lc * $vL + $rc * $vR) / $n;

                if ($imp > $bestScore + 1e-12) {
                    $bestScore = $imp;
                    $best = [
                        'feature' => $f,
                        'threshold' => ($vals[$k] + $vals[$k + 1]) / 2.0,
                        'score' => $imp,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Prediksi pohon tunggal untuk satu baris fitur.
     */
    public function predictTree(array $tree, array $x): float
    {
        $nodeIdx = $tree['root'];
        $nodes = $tree['nodes'] ?? null;

        if ($nodes === null) {
            // struktur: $tree['nodes'] di-set ketika pohon disimpan/dimuat
            $nodes = $tree;
        }

        while (isset($nodes[$nodeIdx]['feature'])) {
            $node = $nodes[$nodeIdx];
            $nodeIdx = $x[$node['feature']] <= $node['threshold'] ? $node['left'] : $node['right'];
        }

        return $nodes[$nodeIdx]['value'] ?? 0.0;
    }

    /**
     * Prediksi rata-rata semua pohon (satu titik).
     */
    public function predict(array $x): float
    {
        $sum = 0.0;
        foreach ($this->trees as $tree) {
            $sum += $this->predictTree($tree, $x);
        }

        return $sum / count($this->trees);
    }

    /**
     * Prediksi seluruh pohon untuk satu baris -> larik prediksi antar-pohon.
     * Dipakai untuk menghitung kuantil Q10/Q50/Q90 (interval prediksi).
     *
     * @return array<int, float>
     */
    public function predictAllTreesOne(array $x): array
    {
        $out = [];
        foreach ($this->trees as $tree) {
            $out[] = $this->predictTree($tree, $x);
        }

        return $out;
    }

    /**
     * Prediksi sebaran antar-pohon untuk banyak baris sekaligus.
     *
     * @param array<int, array<int, float>> $X
     * @return array<int, array<int, float>> [baris][pohon]
     */
    public function predictAllTrees(array $X): array
    {
        $result = [];
        foreach ($X as $i => $x) {
            $result[$i] = $this->predictAllTreesOne($x);
        }

        return $result;
    }

    /**
     * Serialisasi model ke string (storage).
     */
    public function serialize(): string
    {
        return serialize([
            'trees' => $this->trees,
            'nEstimators' => $this->nEstimators,
            'minSamplesLeaf' => $this->minSamplesLeaf,
            'minSamplesSplit' => $this->minSamplesSplit,
            'maxFeaturesMode' => $this->maxFeaturesMode,
            'maxFeaturesCount' => $this->maxFeaturesCount,
            'randomState' => $this->randomState,
            'nFeatures' => $this->nFeatures,
        ]);
    }

    public static function unserializeModel(string $payload): self
    {
        $data = unserialize($payload, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['trees'])) {
            throw new RuntimeException('Model rusak / tidak valid.');
        }

        $rf = new self([
            'n_estimators' => $data['nEstimators'] ?? 500,
            'min_samples_leaf' => $data['minSamplesLeaf'] ?? 5,
            'min_samples_split' => $data['minSamplesSplit'] ?? 2,
            'max_features' => $data['maxFeaturesMode'] ?? 'sqrt',
            'random_state' => $data['randomState'] ?? 42,
        ]);
        $rf->trees = $data['trees'];
        $rf->nFeatures = $data['nFeatures'] ?? count($data['trees'][0]['nodes'] ?? []);
        $rf->maxFeaturesCount = $data['maxFeaturesCount'] ?? $rf->resolveMaxFeatures();

        return $rf;
    }
}