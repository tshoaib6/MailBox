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
        Schema::table('sendportal_subscribers', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_list_id')->nullable()->after('workspace_id')->index();
            $table->foreign('contact_list_id')->references('id')->on('contact_lists')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sendportal_subscribers', function (Blueprint $table) {
            $table->dropForeign(['contact_list_id']);
            $table->dropColumn('contact_list_id');
        });
    }
};
