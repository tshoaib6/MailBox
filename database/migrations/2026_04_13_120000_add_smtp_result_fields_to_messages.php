<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sendportal_messages', function (Blueprint $table) {
            $table->timestamp('attempted_at')->nullable()->after('queued_at')->index();
            $table->string('send_status', 32)->nullable()->after('attempted_at')->index();
            $table->string('smtp_status', 64)->nullable()->after('send_status');
            $table->unsignedSmallInteger('smtp_code')->nullable()->after('smtp_status')->index();
            $table->text('smtp_message')->nullable()->after('smtp_code');
            $table->text('error_detail')->nullable()->after('smtp_message');
        });
    }

    public function down(): void
    {
        Schema::table('sendportal_messages', function (Blueprint $table) {
            $table->dropColumn([
                'attempted_at',
                'send_status',
                'smtp_status',
                'smtp_code',
                'smtp_message',
                'error_detail',
            ]);
        });
    }
};