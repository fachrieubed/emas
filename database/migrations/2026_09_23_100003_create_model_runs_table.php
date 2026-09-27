<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('upload_id')->nullable()->index();
            $table->string('status')->default('processing'); // processing | done | failed
            $table->text('message')->nullable();
            $table->text('error')->nullable();

            // hyperparameter model tsQRF
            $table->integer('n_estimators')->default(500);
            $table->integer('min_samples_leaf')->default(5);
            $table->string('max_features')->default('sqrt');
            $table->integer('min_samples_split')->default(2);
            $table->integer('random_state')->default(42);
            $table->decimal('split_ratio', 5, 4)->default(0.8000);
            $table->integer('horizon')->default(7);

            // info dataset
            $table->integer('total_rows')->default(0);
            $table->integer('train_rows')->default(0);
            $table->integer('test_rows')->default(0);
            $table->date('train_start')->nullable();
            $table->date('train_end')->nullable();
            $table->date('test_start')->nullable();
            $table->date('test_end')->nullable();

            // evaluasi model
            $table->decimal('mae', 16, 4)->nullable();
            $table->decimal('rmse', 16, 4)->nullable();
            $table->decimal('mape', 16, 4)->nullable();
            $table->decimal('coverage', 16, 4)->nullable();
            $table->decimal('avg_interval_width', 16, 4)->nullable();

            $table->decimal('training_seconds', 12, 4)->nullable();
            $table->string('model_path')->nullable();

            $table->timestamps();

            $table->foreign('upload_id')->references('id')->on('uploads')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_runs');
    }
};