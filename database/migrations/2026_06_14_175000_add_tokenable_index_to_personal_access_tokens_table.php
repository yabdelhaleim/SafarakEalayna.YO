<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            $indexes = Schema::getIndexes('personal_access_tokens');
            $indexNames = array_column($indexes, 'name');
            $targetIndex = 'personal_access_tokens_tokenable_id_tokenable_type_index';

            if (!in_array($targetIndex, $indexNames)) {
                Schema::table('personal_access_tokens', function (Blueprint $table) {
                    $table->index(['tokenable_id', 'tokenable_type'], 'personal_access_tokens_tokenable_id_tokenable_type_index');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            $indexes = Schema::getIndexes('personal_access_tokens');
            $indexNames = array_column($indexes, 'name');
            $targetIndex = 'personal_access_tokens_tokenable_id_tokenable_type_index';

            if (in_array($targetIndex, $indexNames)) {
                Schema::table('personal_access_tokens', function (Blueprint $table) {
                    $table->dropIndex('personal_access_tokens_tokenable_id_tokenable_type_index');
                });
            }
        }
    }
};
