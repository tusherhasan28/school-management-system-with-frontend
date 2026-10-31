<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Milestone 7: full data export of a school (ZIP of CSV files), kept for a few days. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->tenantForeign('requested_by', 'users', nullable: true);
            $table->enum('status', ['queued', 'processing', 'ready', 'failed', 'expired'])->default('queued');
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('counts')->nullable();
            $table->string('error_message', 255)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
