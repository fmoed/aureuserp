<?php

namespace Webkul\Attendance\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

class AttendancePolicy
{
    use HandlesAuthorization;

    /**
     * Company-level access for non-global scopes. The attendance owner is
     * an employee, and employees carry no teams, so the generic team-based
     * group check would crash here. Company membership is the correct
     * boundary; rows without a company stay visible to global users only.
     */
    private function hasCompanyAccess(User $user, Attendance $attendance): bool
    {
        if ($user->resource_permission === PermissionType::GLOBAL) {
            return true;
        }

        return $attendance->company_id !== null
            && $user->allowedCompanies->contains('id', $attendance->company_id);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_attendance_attendance');
    }

    /**
     * Scope check: GLOBAL sees every row in their companies; INDIVIDUAL and
     * GROUP see rows they own (creator, the employee's own user account, or
     * the employee's attendance manager). INDIVIDUAL never matches a row
     * without an owner, so employees without a user account stay hidden from
     * individual profiles; GROUP keeps company-wide visibility for ownerless
     * rows in their teams.
     */
    private function hasScopeAccess(User $user, Attendance $attendance): bool
    {
        if ($user->resource_permission === PermissionType::GLOBAL) {
            return true;
        }

        $authorizedUserIds = bouncer()->getAuthorizedUserIds() ?? [];

        if ($authorizedUserIds === []) {
            if ($user->resource_permission !== PermissionType::GROUP) {
                return false;
            }

            // A group user without teams still owns the rows they created.
            $authorizedUserIds = [$user->id];
        }

        $ownerIds = array_values(array_filter([
            $attendance->creator_id,
            $attendance->employee?->user_id,
            $attendance->employee?->attendance_manager_id,
        ]));

        if ($user->resource_permission === PermissionType::INDIVIDUAL) {
            return array_intersect($ownerIds, $authorizedUserIds) !== [];
        }

        if ($ownerIds === []) {
            return true;
        }

        return array_intersect($ownerIds, $authorizedUserIds) !== [];
    }

    /**
     * Mirror of hasScopeAccess() for list/table queries: INDIVIDUAL and
     * GROUP only see rows whose creator, employee user, or employee
     * attendance manager is inside their authorized user set.
     */
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
            $inner->whereIn('creator_id', $authorizedUserIds)
                ->orWhereHas('employee', function (Builder $employee) use ($authorizedUserIds): void {
                    $employee->whereIn('user_id', $authorizedUserIds)
                        ->orWhereIn('attendance_manager_id', $authorizedUserIds);
                });

            // hasScopeAccess() treats an ownerless row as visible to GROUP.
            if ($user->resource_permission === PermissionType::GROUP) {
                $inner->orWhere(fn (Builder $ownerless) => $ownerless
                    ->whereNull('creator_id')
                    ->whereHas('employee', fn (Builder $employee) => $employee
                        ->whereNull('user_id')
                        ->whereNull('attendance_manager_id')));
            }
        });
    }

    /**
     * Employee-side mirror of scopeQuery(): INDIVIDUAL and GROUP only see
     * employees whose user account, attendance manager, or creator is
     * inside their authorized user set. Used by every employee picker and
     * register query so a scoped user never sees the whole company roster.
     */
    public static function scopeEmployeeQuery(Builder $query, ?User $user = null): Builder
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
            $inner->whereIn('user_id', $authorizedUserIds)
                ->orWhereIn('attendance_manager_id', $authorizedUserIds)
                ->orWhereIn('creator_id', $authorizedUserIds);

            if ($user->resource_permission === PermissionType::GROUP) {
                $inner->orWhere(fn (Builder $ownerless) => $ownerless
                    ->whereNull('user_id')
                    ->whereNull('attendance_manager_id')
                    ->whereNull('creator_id'));
            }
        });
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Attendance $attendance): bool
    {
        if (! $user->can('view_attendance_attendance')) {
            return false;
        }

        return $this->hasCompanyAccess($user, $attendance)
            && $this->hasScopeAccess($user, $attendance);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_attendance_attendance');
    }

    /**
     * Determine whether the user can update the model.
     *
     * Open rows (missing check-out) can be completed regardless of age or
     * source. This is deliberate: device-derived rows may stay open for
     * weeks (missed punch, stale sync), and locking them would make the
     * backlog unfixable. Closed manual rows stay editable while their work
     * day is recent (today or yesterday) to fix human errors. Closed device
     * rows are frozen: their origin must never change.
     */
    public function update(User $user, ?Attendance $attendance = null): bool
    {
        if (! $user->can('update_attendance_attendance')) {
            return false;
        }

        if ($attendance === null) {
            return true;
        }

        if (! $this->hasCompanyAccess($user, $attendance)) {
            return false;
        }

        if (! $this->hasScopeAccess($user, $attendance)) {
            return false;
        }

        if ($attendance->work_date !== null && AttendanceSettings::isLockedDate($attendance->work_date)) {
            return false;
        }

        if ($attendance->check_out === null) {
            return true;
        }

        return $attendance->source === 'manual';
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Any manual row can be deleted (device rows never are). Older manual
     * rows are fixed via delete + recreate instead of silent edits, so the
     * correction stays visible in the activity log.
     */
    public function delete(User $user, ?Attendance $attendance = null): bool
    {
        if (! $user->can('delete_attendance_attendance')) {
            return false;
        }

        if ($attendance === null) {
            return true;
        }

        if (! $this->hasCompanyAccess($user, $attendance)) {
            return false;
        }

        if (! $this->hasScopeAccess($user, $attendance)) {
            return false;
        }

        if ($attendance->work_date !== null && AttendanceSettings::isLockedDate($attendance->work_date)) {
            return false;
        }

        return $attendance->source === 'manual';
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_attendance_attendance');
    }
}
