<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    protected $fillable = [
        'original_name',
        'stored_path',
        'total_rows',
        'parsed_rows',
        'status',
        'message',
        'error',
    ];

    public function goldPrices(): HasMany
    {
        return $this->hasMany(GoldPrice::class);
    }

    public function modelRuns(): HasMany
    {
        return $this->hasMany(ModelRun::class);
    }
}