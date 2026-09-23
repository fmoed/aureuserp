<?php

namespace Webkul\BiometricAttendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Employee\Models\Employee;

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
    ];

    protected $casts = [
        'punch_time' => 'datetime',
        'raw_state'  => 'integer',
        'raw_type'   => 'integer',
        'device_uid' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
