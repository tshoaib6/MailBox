<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if foreign key exists and drop it
        $foreignKeyExists = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'contact_lists' 
            AND CONSTRAINT_NAME = 'contact_lists_workspace_id_foreign'
        ");

        if (!empty($foreignKeyExists)) {
            DB::statement('ALTER TABLE contact_lists DROP FOREIGN KEY contact_lists_workspace_id_foreign');
        }

        // Change column type to BIGINT UNSIGNED
        DB::statement('ALTER TABLE contact_lists MODIFY workspace_id BIGINT UNSIGNED NOT NULL');

        // Re-add foreign key
        DB::statement('ALTER TABLE contact_lists ADD CONSTRAINT contact_lists_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key
        DB::statement('ALTER TABLE contact_lists DROP FOREIGN KEY contact_lists_workspace_id_foreign');

        // Change back to INT UNSIGNED
        DB::statement('ALTER TABLE contact_lists MODIFY workspace_id INT UNSIGNED NOT NULL');

        // Re-add original foreign key
        DB::statement('ALTER TABLE contact_lists ADD CONSTRAINT contact_lists_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE');
    }
};
