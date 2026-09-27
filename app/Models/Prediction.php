<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $fillable = [
        'model_run_id',
        'kind',
        'tanggal',
        'actual',
        'q10',
        'q50',
        'q90',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'actual' => 'float',
        'q10' => 'float',
        'q50' => 'float',
        'q90' => 'float',
    ];

    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }
}