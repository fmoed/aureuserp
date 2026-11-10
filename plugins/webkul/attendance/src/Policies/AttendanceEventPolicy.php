<?php

namespace Webkul\Attendance\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

class AttendanceEventPolicy
{
    use HandlesAuthorization;

    private function hasCompanyAccess(User $user, AttendanceEvent $attendanceEvent): bool
    {
        if ($user->resource_permission === PermissionType::GLOBAL) {
            return true;
        }

        return $attendanceEvent->company_id !== null
            && $user->allowedCompanies->contains('id', $attendanceEvent->company_id);
    }

    private function hasScopeAccess(User $user, AttendanceEvent $attendanceEvent): bool
    {
        if ($user->resource_permission === PermissionType::GLOBAL) {
            return true;
        }

        $authorizedUserIds = bouncer()->getAuthorizedUserIds() ?? [];

        if ($authorizedUserIds === []) {
            if ($user->resource_permission !== PermissionType::GROUP) {
                return false;
            }

            $authorizedUserIds = [$user->id];
        }

        $ownerIds = array_values(array_filter([
            $attendanceEvent->employee?->user_id,
            $attendanceEvent->employee?->attendance_manager_id,
        ]));

        if ($user->resource_permission === PermissionType::INDIVIDUAL) {
            return array_intersect($ownerIds, $authorizedUserIds) !== [];
        }

        if ($ownerIds === []) {
            return true;
        }

        return array_intersect($ownerIds, $authorizedUserIds) !== [];
    }

    public static function scopeQuery(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || $user->resource_permission === PermissionType::GLOBAL) {
            return $query;
        }

        $authorizedUserIds = bouncer()->getAuthorizedUserIds() ?? [];

        if ($authorizedUserIds === []) {
            if ($user->resource_permission !== PermissionType::GROUP) {
                return $query->whereIn($query->getModel()->getQualifiedKeyName(), []);
            }

            $authorizedUserIds = [$user->id];
        }

        return $query->where(function (Builder $inner) use ($authorizedUserIds, $user): void {
            $inner->whereHas('employee', function (Builder $employee) use ($authorizedUserIds): void {
                $employee->whereIn('user_id', $authorizedUserIds)
                    ->orWhereIn('attendance_manager_id', $authorizedUserIds);
            });

            // hasScopeAccess() treats an event without an owner as visible to GROUP.
            if ($user->resource_permission === PermissionType::GROUP) {
                $inner->orWhereNull('employee_id')
                    ->orWhereHas('employee', fn (Builder $employee) => $employee
                        ->whereNull('user_id')
                        ->whereNull('attendance_manager_id'));
            }
        });
    }

    /**
     * Determine whether the user can view any models.
     *
     * Key shape follows PermissionManager: the multi-word resource
     * `AttendanceEvent` becomes `attendance::event`, so the generated
     * Shield rows are `view_any_attendance_attendance::event` (verified
     * in the permissions table, not assumed).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_attendance_attendance::event');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AttendanceEvent $attendanceEvent): bool
    {
        if (! $user->can('view_attendance_attendance::event')) {
            return false;
        }

        return $this->hasCompanyAccess($user, $attendanceEvent)
            && $this->hasScopeAccess($user, $attendanceEvent);
    }
}
