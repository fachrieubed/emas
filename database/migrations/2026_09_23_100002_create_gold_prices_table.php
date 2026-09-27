<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gold_prices', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal')->unique();
            $table->decimal('terakhir', 16, 4);
            $table->decimal('pembukaan', 16, 4);
            $table->decimal('tertinggi', 16, 4);
            $table->decimal('terendah', 16, 4);
            $table->decimal('volume', 20, 4)->nullable();
            $table->decimal('perubahan', 12, 4)->nullable(); // in percent
            $table->unsignedBigInteger('upload_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('upload_id')->references('id')->on('uploads')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_prices');
    }
};