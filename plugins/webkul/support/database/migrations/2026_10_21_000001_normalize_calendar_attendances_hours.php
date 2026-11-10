<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Webkul\Support\Models\CalendarAttendance;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy rows stored clock strings ("08:30", "08:30:00"); the model now
     * persists decimal hours. Normalize existing values once so calendar math
     * and TimePicker round-trips stay consistent.
     */
    public function up(): void
    {
        if (! Schema::hasTable('calendar_attendances')) {
            return;
        }

        CalendarAttendance::query()
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach (['hour_from', 'hour_to'] as $column) {
                        $raw = $row->getRawOriginal($column);

                        if (! is_string($raw) || ! preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', trim($raw))) {
                            continue;
                        }

                        $updates[$column] = (string) round(CalendarAttendance::minutesOfDay($raw) / 60, 4);
                    }

                    if ($updates !== []) {
                        CalendarAttendance::query()->whereKey($row->getKey())->update($updates);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
