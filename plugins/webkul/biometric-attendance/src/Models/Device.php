<?php

namespace Webkul\BiometricAttendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

class Device extends Model
{
    protected $table = 'biometric_devices';

    protected $fillable = [
        'name',
        'host',
        'port',
        'protocol',
        'comm_key',
        'timezone',
        'is_active',
        'company_id',
        'creator_id',
        'last_sync_at',
        'last_error',
    ];

    protected $casts = [
        'port'         => 'integer',
        'comm_key'     => 'integer',
        'is_active'    => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
