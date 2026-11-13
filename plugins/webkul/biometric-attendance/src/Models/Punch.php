<?php

namespace Webkul\BiometricAttendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Scopes\CompanyScope;

class Punch extends Model
{
    protected $table = 'biometric_punches';

    protected $fillable = [
        'device_id',
        'zk_user_id',
        'employee_id',
        'punch_time',
        'raw_state',
        'raw_type',
        'device_uid',
        'pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'punch_time' => 'datetime',
            'raw_state'  => 'integer',
            'raw_type'   => 'integer',
            'device_uid' => 'integer',
            'pushed_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Punch $punch): void {
            if ($punch->isDirty('employee_id') && $punch->getOriginal('employee_id') !== null) {
                $punch->pushed_at = null;
            }
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id')
            ->withoutGlobalScope(CompanyScope::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')
            ->withoutGlobalScope(CompanyScope::class);
    }
}
