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
        if (Schema::hasTable('contact_list_column_mappings')) {
            return;
        }

        Schema::create('contact_list_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_list_id')->index();
            $table->string('csv_column')->comment('Column from CSV e.g. "Email", "First Name"');
            $table->string('merge_variable')->comment('Merge tag e.g. "email", "first_name"');
            $table->timestamps();

            $table->foreign('contact_list_id')->references('id')->on('contact_lists')->onDelete('cascade');
            $table->unique(['contact_list_id', 'csv_column']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_list_column_mappings');
    }
};
