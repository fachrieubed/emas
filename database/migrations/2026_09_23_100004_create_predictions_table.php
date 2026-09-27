<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('model_run_id')->index();
            $table->string('kind'); // test | forecast
            $table->date('tanggal');
            $table->decimal('actual', 16, 4)->nullable();
            $table->decimal('q10', 16, 4);
            $table->decimal('q50', 16, 4);
            $table->decimal('q90', 16, 4);
            $table->timestamps();

            $table->foreign('model_run_id')->references('id')->on('model_runs')->onDelete('cascade');
            $table->unique(['model_run_id', 'kind', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};