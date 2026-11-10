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
        Schema::create('biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->comment('Source Device');
            $table->string('zk_user_id')->comment('Raw User ID From Device (kept as string)');
            $table->unsignedBigInteger('employee_id')->nullable()->comment('Matched Employee (filled later)');
            $table->dateTime('punch_time')->nullable()->comment('Device Wall-Clock Time');
            $table->unsignedInteger('raw_state')->default(0)->comment('Raw State From Device');
            $table->unsignedInteger('raw_type')->default(0)->comment('Raw Type From Device');
            $table->unsignedInteger('device_uid')->comment('Record Number On Device');

            $table->foreign('device_id')->references('id')->on('biometric_devices')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees_employees')->onDelete('set null');

            $table->unique(['device_id', 'device_uid']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_punches');
    }
};
