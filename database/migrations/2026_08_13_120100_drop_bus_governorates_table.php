<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-9 Cleanup — Drop the legacy `bus_governorates` table.
 *
 * Summary
 * -------
 * `bus_governorates` was a planned-but-unused lookup table (added 2026-05-08)
 * that was never wired into any:
 *   - API route
 *   - Controller
 *   - Service
 *   - Vue/frontend page
 *   - Foreign key from any other table
 *
 * Per the F-9 investigation report (`BUS_MODULE_F9_BUSGOVERNORATE_INVESTIGATION_20260813.md`),
 * the table had 0 production rows and no live consumers. The model, Filament
 * resource, Filament page, and `seedGovernorates()` seeder method have all been
 * removed (see git history for F-8/F-9 cleanup commits).
 *
 * This migration is fully reversible:
 *   - `up()`   drops the table if present
 *   - `down()` recreates the original schema exactly (id, name UNIQUE,
 *             is_active BOOL, sort_order UNSIGNED INT, timestamps)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bus_governorates');
    }

    public function down(): void
    {
        Schema::create('bus_governorates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }
};
