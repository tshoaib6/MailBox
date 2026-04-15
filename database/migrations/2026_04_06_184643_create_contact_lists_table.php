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
        if (Schema::hasTable('contact_lists')) {
            return;
        }

        Schema::create('contact_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('workspace_id')->index();
            $table->string('name');
            $table->json('columns')->nullable()->comment('Array of CSV column names: ["email", "first_name", "company"]');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->unique(['workspace_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_lists');
    }
};
