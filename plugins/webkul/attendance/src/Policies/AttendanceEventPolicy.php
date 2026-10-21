<?php

namespace Webkul\Attendance\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Security\Models\User;

class AttendanceEventPolicy
{
    use HandlesAuthorization;

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
        return $user->can('view_attendance_attendance::event');
    }
}
