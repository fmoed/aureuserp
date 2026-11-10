<?php

namespace Webkul\BiometricAttendance\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

class DevicePolicy
{
    use HandlesAuthorization;

    private function hasCompanyAccess(User $user, Device $device): bool
    {
        if ($user->resource_permission === PermissionType::GLOBAL) {
            return true;
        }

        return $device->company_id !== null
            && $user->allowedCompanies->contains('id', $device->company_id);
    }

    private function hasScopeAccess(User $user, Device $device): bool
    {
        if ($user->resource_permission === PermissionType::GLOBAL) {
            return true;
        }

        $authorizedUserIds = bouncer()->getAuthorizedUserIds() ?? [];

        if ($authorizedUserIds === []) {
            return false;
        }

        if ($device->creator_id === null) {
            return true;
        }

        return in_array($device->creator_id, $authorizedUserIds, true);
    }

    /**
     * List/table mirror of hasScopeAccess(): GLOBAL sees every device in
     * their companies; other profiles see creator-owned and unowned rows
     * whose company they may access.
     */
    public static function scopeQuery(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || $user->resource_permission === PermissionType::GLOBAL) {
            return $query;
        }

        $authorizedUserIds = bouncer()->getAuthorizedUserIds() ?? [];

        if ($authorizedUserIds === []) {
            return $query->whereIn($query->getModel()->getQualifiedKeyName(), []);
        }

        return $query->where(function (Builder $inner) use ($authorizedUserIds): void {
            $inner->whereNull('creator_id')->orWhereIn('creator_id', $authorizedUserIds);
        });
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_biometric_attendance_device');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Device $device): bool
    {
        return $user->can('view_biometric_attendance_device')
            && $this->hasCompanyAccess($user, $device)
            && $this->hasScopeAccess($user, $device);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_biometric_attendance_device');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Device $device): bool
    {
        return $user->can('update_biometric_attendance_device')
            && $this->hasCompanyAccess($user, $device)
            && $this->hasScopeAccess($user, $device);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Device $device): bool
    {
        return $user->can('delete_biometric_attendance_device')
            && $this->hasCompanyAccess($user, $device)
            && $this->hasScopeAccess($user, $device);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_biometric_attendance_device');
    }
}
