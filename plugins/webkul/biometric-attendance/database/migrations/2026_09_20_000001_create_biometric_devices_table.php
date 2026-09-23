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
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Device Name');
            $table->string('host')->comment('Device IP or Hostname');
            $table->unsignedSmallInteger('port')->default(4370)->comment('Device Port');
            $table->string('protocol', 8)->default('tcp')->comment('Connection Protocol (tcp/udp)');
            $table->unsignedInteger('comm_key')->default(0)->comment('Device COMM Password (0 = none)');
            $table->string('timezone')->default('UTC')->comment('Device Wall-Clock Timezone');
            $table->boolean('is_active')->default(true)->comment('Status');
            $table->unsignedBigInteger('company_id')->nullable()->comment('Company');
            $table->unsignedBigInteger('creator_id')->nullable()->comment('Created By');
            $table->timestamp('last_sync_at')->nullable()->comment('Last Successful Read');
            $table->text('last_error')->nullable()->comment('Last Connection Error');

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('set null');
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
