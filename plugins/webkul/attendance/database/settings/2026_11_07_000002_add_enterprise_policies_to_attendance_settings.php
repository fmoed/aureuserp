<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'default_missing_checkout_policy' => 'incomplete_absent',
            'half_day_threshold_percent'      => 50,
            'early_arrival_policy'            => 'clamp_to_schedule',
            'max_early_offset_minutes'        => 60,
            'holiday_policy'                  => 'excuse_all_applicable',
            'mandatory_days_policy'           => 'treat_as_holiday',
            'count_unpaid_leave_as_absent'    => false,
            'rounding_mode'                   => 'none',
            'attendance_lock_date'            => null,
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists('attendance.'.$key)) {
                $this->migrator->add('attendance.'.$key, $value);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'default_missing_checkout_policy',
            'half_day_threshold_percent',
            'early_arrival_policy',
            'max_early_offset_minutes',
            'holiday_policy',
            'mandatory_days_policy',
            'count_unpaid_leave_as_absent',
            'rounding_mode',
            'attendance_lock_date',
        ] as $key) {
            $this->migrator->deleteIfExists('attendance.'.$key);
        }
    }
};
