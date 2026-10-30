<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie teams use school_id as the team key. Tie it to schools so a role or
 * assignment can never reference a school that does not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['school_id']);
            });
        }
    }
};
