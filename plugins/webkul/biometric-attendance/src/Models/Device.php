<?php

namespace Webkul\BiometricAttendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\BelongsToCompany;

class Device extends Model
{
    use BelongsToCompany;

    protected $table = 'biometric_devices';

    protected $fillable = [
        'name',
        'host',
        'port',
        'protocol',
        'comm_key',
        'timezone',
        'punch_direction_mode',
        'is_active',
        'company_id',
        'creator_id',
        'last_sync_at',
        'last_sync_uid',
        'last_error',
    ];

    protected $casts = [
        'port'          => 'integer',
        'comm_key'      => 'integer',
        'is_active'     => 'boolean',
        'last_sync_at'  => 'datetime',
        'last_sync_uid' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Device $device): void {
            $device->creator_id ??= Auth::id();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class, 'device_id');
    }

    /**
     * Resolve a console/device-page identifier (numeric id or unique name)
     * across companies, so a shared device is reachable from any tenant.
     */
    public static function findByIdentifier(string $identifier): ?self
    {
        return static::withoutGlobalScope(CompanyScope::class)
            ->when(is_numeric($identifier), fn ($query) => $query->where('id', $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('name', $identifier))
            ->first();
    }
}
