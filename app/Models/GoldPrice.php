<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoldPrice extends Model
{
    protected $fillable = [
        'tanggal',
        'terakhir',
        'pembukaan',
        'tertinggi',
        'terendah',
        'volume',
        'perubahan',
        'upload_id',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'terakhir' => 'float',
        'pembukaan' => 'float',
        'tertinggi' => 'float',
        'terendah' => 'float',
        'volume' => 'float',
        'perubahan' => 'float',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /**
     * Pencarian fleksibel: tanggal dalam berbagai format (d/m/Y, m/d/Y, Y-m-d,
     * bulan/tahun) serta harga dengan atau tanpa titik ribuan.
     */
    public function scopeSearch($query, ?string $search)
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }

        $like = "%{$search}%";
        $clean = str_replace([',', '.'], '', $search); // "4.376,40" -> "437640"

        return $query->where(function ($q) use ($search, $like, $clean) {
            $q->whereRaw("DATE_FORMAT(tanggal, '%d/%m/%Y') LIKE ?", [$like])
                ->orWhereRaw("DATE_FORMAT(tanggal, '%m/%d/%Y') LIKE ?", [$like])
                ->orWhereRaw("DATE_FORMAT(tanggal, '%m/%Y') LIKE ?", [$like])
                ->orWhere('tanggal', 'like', $like)
                ->orWhere('terakhir', 'like', $like)
                ->orWhere('pembukaan', 'like', $like)
                ->orWhere('tertinggi', 'like', $like)
                ->orWhere('terendah', 'like', $like);

            if ($clean !== '') {
                $q->orWhereRaw("REPLACE(REPLACE(terakhir, '.', ''), ',', '') LIKE ?", ['%' . $clean . '%'])
                    ->orWhereRaw("REPLACE(REPLACE(pembukaan, '.', ''), ',', '') LIKE ?", ['%' . $clean . '%'])
                    ->orWhereRaw("REPLACE(REPLACE(tertinggi, '.', ''), ',', '') LIKE ?", ['%' . $clean . '%'])
                    ->orWhereRaw("REPLACE(REPLACE(terendah, '.', ''), ',', '') LIKE ?", ['%' . $clean . '%']);
            }
        });
    }
}