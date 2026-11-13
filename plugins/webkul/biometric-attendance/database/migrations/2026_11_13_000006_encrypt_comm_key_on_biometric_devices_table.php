<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('biometric_devices')->select(['id', 'comm_key'])->get();

        Schema::table('biometric_devices', function (Blueprint $table): void {
            $table->text('comm_key')->nullable()->comment('Encrypted Device COMM Password')->change();
        });

        foreach ($rows as $row) {
            $raw = (string) ($row->comm_key ?? '0');

            try {
                Crypt::decryptString($raw);
                $encrypted = $raw;
            } catch (Throwable) {
                $encrypted = Crypt::encryptString($raw);
            }

            DB::table('biometric_devices')
                ->where('id', $row->id)
                ->update(['comm_key' => $encrypted]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('biometric_devices')->select(['id', 'comm_key'])->get();

        foreach ($rows as $row) {
            $plain = 0;

            if ($row->comm_key !== null && $row->comm_key !== '') {
                try {
                    $plain = max(0, (int) Crypt::decryptString((string) $row->comm_key));
                } catch (Throwable) {
                    $plain = is_numeric($row->comm_key) ? max(0, (int) $row->comm_key) : 0;
                }
            }

            DB::table('biometric_devices')
                ->where('id', $row->id)
                ->update(['comm_key' => (string) $plain]);
        }

        Schema::table('biometric_devices', function (Blueprint $table): void {
            $table->unsignedInteger('comm_key')->default(0)->comment('Device COMM Password (0 = none)')->change();
        });
    }
};
