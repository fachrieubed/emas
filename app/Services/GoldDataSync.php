<?php

namespace App\Services;

use App\Models\GoldPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menyinkronkan data emas dari sumber (Yahoo Finance GC=F):
 * menambal tanggal perdagangan yang belum ada (mis. hari Jumat yang bolong),
 * menyegarkan data terbaru, menghapus baris weekend/baris berkolom kosong,
 * lalu menghitung ulang Perubahan% mengikuti urutan hari perdagangan.
 *
 * Dipanggil oleh command `gold:update` dan secara best-effort setelah
 * upload CSV manual agar dataset selalu lengkap.
 */
class GoldDataSync
{
    public const SOURCE = 'Yahoo Finance (GC=F)';

    public function __construct(
        protected GoldDataFetcher $fetcher,
    ) {
    }

    /**
     * Jalankan satu putaran sinkronisasi terhadap isi database saat ini.
     *
     * @return array{
     *     inserted: int, updated: int, kept: int,
     *     deleted_weekend: int, deleted_empty: int,
     *     changed: int, total: int, latest_date: ?string,
     *     source: string, filled: array<int, string>
     * }
     *
     * @throws \RuntimeException bila sumber data tidak dapat diakses / kosong
     */
    public function sync(int $days = 90): array
    {
        $minDate = GoldPrice::query()->weekdaysOnly()->min('tanggal');

        $rows = $minDate ? $this->fetcher->fetchSince($minDate) : $this->fetcher->fetch($days);

        if (count($rows) === 0) {
            throw new \RuntimeException('Tidak ada data yang bisa diambil dari sumber data.');
        }

        // Jangan menambah data sebelum tanggal awal dataset.
        if ($minDate) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['tanggal'] >= $minDate));
        }

        $existing = GoldPrice::query()->pluck('tanggal')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10))
            ->flip();

        $recentCutoff = (new \DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d');

        $inserted = 0;
        $updated = 0;
        $kept = 0;
        $filled = [];

        DB::transaction(function () use ($rows, $existing, $recentCutoff, &$inserted, &$updated, &$kept, &$filled) {
            foreach ($rows as $row) {
                $date = $row['tanggal'];
                $payload = [
                    'tanggal' => $date,
                    'pembukaan' => $row['pembukaan'],
                    'tertinggi' => $row['tertinggi'],
                    'terendah' => $row['terendah'],
                    'terakhir' => $row['terakhir'],
                    'volume' => $row['volume'],
                ];

                if (!$existing->has($date)) {
                    $payload['perubahan'] = 0; // diisi pada tahap hitung ulang
                    GoldPrice::create($payload);
                    $inserted++;
                    $filled[] = $date;
                } elseif ($date >= $recentCutoff) {
                    // segarkan data terbaru agar nilai janggal ikut diperbaiki
                    GoldPrice::query()->where('tanggal', $date)->update($payload);
                    $updated++;
                } else {
                    $kept++; // data lama sudah benar & cocok dengan sumber
                }
            }
        });

        // Kebijakan "tidak boleh ada data kosong": buang baris weekend & baris berkolom kosong.
        $deletedWeekend = GoldPrice::query()->whereRaw('DAYOFWEEK(tanggal) IN (1, 7)')->delete();
        $deletedEmpty = GoldPrice::query()
            ->where(function ($q) {
                $q->whereNull('terakhir')->orWhereNull('pembukaan')->orWhereNull('tertinggi')
                    ->orWhereNull('terendah')->orWhereNull('volume')->orWhereNull('perubahan');
            })
            ->delete();

        // Hitung ulang Perubahan% mengikuti urutan hari perdagangan (setelah tanggal ditambal).
        $changed = $this->recomputeChange();

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'kept' => $kept,
            'deleted_weekend' => $deletedWeekend,
            'deleted_empty' => $deletedEmpty,
            'changed' => $changed,
            'total' => GoldPrice::query()->weekdaysOnly()->count(),
            'latest_date' => GoldPrice::query()->weekdaysOnly()->max('tanggal'),
            'source' => self::SOURCE,
            'filled' => $filled,
        ];
    }

    /**
     * Hitung ulang kolom Perubahan% = (penutupan_t - penutupan_{t-1}) / penutupan_{t-1} x 100
     * berdasarkan urutan hari perdagangan yang sudah bersih.
     */
    public function recomputeChange(): int
    {
        $rows = GoldPrice::query()->weekdaysOnly()->orderBy('tanggal')->get(['id', 'terakhir', 'perubahan']);
        $prev = null;
        $changed = 0;

        DB::transaction(function () use ($rows, &$prev, &$changed) {
            foreach ($rows as $g) {
                $close = (float) $g->terakhir;
                $chg = ($prev !== null && $prev != 0.0)
                    ? round(($close - $prev) / $prev * 100, 2)
                    : ($g->perubahan !== null ? (float) $g->perubahan : 0.0);

                if ((float) $g->perubahan !== (float) $chg) {
                    GoldPrice::query()->whereKey($g->id)->update(['perubahan' => $chg]);
                    $changed++;
                }

                $prev = $close;
            }
        });

        return $changed;
    }
}