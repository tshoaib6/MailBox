<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove any duplicate rows first (keep the one with the lowest id).
        DB::statement('
            DELETE m1 FROM sendportal_messages m1
            INNER JOIN sendportal_messages m2
                ON  m1.workspace_id  = m2.workspace_id
                AND m1.subscriber_id = m2.subscriber_id
                AND m1.source_type   = m2.source_type
                AND m1.source_id     = m2.source_id
                AND m1.id > m2.id
        ');

        Schema::table('sendportal_messages', function (Blueprint $table) {
            $table->unique(
                ['workspace_id', 'subscriber_id', 'source_type', 'source_id'],
                'sendportal_messages_campaign_subscriber_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sendportal_messages', function (Blueprint $table) {
            $table->dropUnique('sendportal_messages_campaign_subscriber_unique');
        });
    }
};
