<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelRun extends Model
{
    protected $fillable = [
        'upload_id',
        'status',
        'message',
        'error',
        'n_estimators',
        'min_samples_leaf',
        'max_features',
        'min_samples_split',
        'random_state',
        'split_ratio',
        'horizon',
        'total_rows',
        'train_rows',
        'test_rows',
        'train_start',
        'train_end',
        'test_start',
        'test_end',
        'mae',
        'rmse',
        'mape',
        'coverage',
        'avg_interval_width',
        'training_seconds',
        'model_path',
    ];

    protected $casts = [
        'split_ratio' => 'float',
        'train_start' => 'date',
        'train_end' => 'date',
        'test_start' => 'date',
        'test_end' => 'date',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function testPredictions(): HasMany
    {
        return $this->hasMany(Prediction::class)->where('kind', 'test');
    }

    public function forecastPredictions(): HasMany
    {
        return $this->hasMany(Prediction::class)->where('kind', 'forecast');
    }
}