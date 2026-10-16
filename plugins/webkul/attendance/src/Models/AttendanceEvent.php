<?php

namespace Webkul\Attendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\BelongsToCompany;

class AttendanceEvent extends Model
{
    use BelongsToCompany;

    public const DIRECTION_IN = 1;

    public const DIRECTION_OUT = -1;

    public const DIRECTION_UNKNOWN = 0;

    /**
     * Human label for a punch direction. The device `state` byte is the
     * verify mode, not IN/OUT, so most punches stay unknown until a
     * hardware check proves otherwise. Pairing ignores direction anyway.
     */
    public static function directionLabel(int $direction): string
    {
        return match ($direction) {
            self::DIRECTION_IN  => __('attendance::filament/resources/attendance-event.table.directions.in'),
            self::DIRECTION_OUT => __('attendance::filament/resources/attendance-event.table.directions.out'),
            default             => __('attendance::filament/resources/attendance-event.table.directions.unknown'),
        };
    }

    protected $table = 'attendance_events';

    protected $fillable = [
        'employee_id',
        'punched_at',
        'direction',
        'source',
        'source_label',
        'source_ref',
        'attendance_id',
        'company_id',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
        'direction'  => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
