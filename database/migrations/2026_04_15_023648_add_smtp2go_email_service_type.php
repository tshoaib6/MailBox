<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sendportal_email_service_types')->insert([
            'id'         => 8,
            'name'       => 'Smtp2go',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('sendportal_email_service_types')->where('id', 8)->delete();
    }
};
