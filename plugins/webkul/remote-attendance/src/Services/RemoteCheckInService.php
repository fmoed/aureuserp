<?php

namespace Webkul\RemoteAttendance\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\ShiftWindowResolver;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Employee\Models\Employee;
use Webkul\Project\Enums\TaskState;
use Webkul\Project\Filament\Resources\TaskResource;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\Task;
use Webkul\Project\Models\TaskStage;
use Webkul\Project\Models\Timesheet;
use Webkul\RemoteAttendance\Settings\RemoteAttendanceSettings;
use Webkul\Support\Models\Scopes\CompanyScope;

class RemoteCheckInService
{
    public const SOURCE_REMOTE = 'remote';

    public static function applyEligibilityScope(Builder $query): Builder
    {
        $query->where('is_active', true);

        if (! RemoteAttendanceSettings::onlyHomeLocation()) {
            return $query;
        }

        $allowedTypes = RemoteAttendanceSettings::allowedLocationTypes();
        $allowedLocationIds = RemoteAttendanceSettings::allowedWorkLocationIds();

        if ($allowedTypes === [] && $allowedLocationIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('workLocation', function (Builder $location) use ($allowedTypes, $allowedLocationIds): void {
            $location->where(fn (Builder $active) => $active->where('is_active', true)->orWhereNull('is_active'));

            $location->where(function (Builder $match) use ($allowedTypes, $allowedLocationIds): void {
                if ($allowedLocationIds !== []) {
                    $match->whereIn('id', $allowedLocationIds);
                }

                if ($allowedTypes !== []) {
                    $method = $allowedLocationIds !== [] ? 'orWhereIn' : 'whereIn';
                    $match->{$method}('location_type', $allowedTypes);
                }
            });
        });
    }

    public static function projectsAvailable(): bool
    {
        return class_exists(Project::class)
            && class_exists(Task::class)
            && class_exists(Timesheet::class)
            && Schema::hasTable('projects_projects')
            && Schema::hasTable('projects_tasks')
            && Schema::hasTable('analytic_records');
    }

    public function isEligible(Employee $employee): bool
    {
        if (! $employee->is_active) {
            return false;
        }

        if (! RemoteAttendanceSettings::onlyHomeLocation()) {
            return true;
        }

        $location = $employee->workLocation
            ?? $employee->workLocation()->withoutGlobalScope(CompanyScope::class)->first();

        if (! $location) {
            return false;
        }

        $isActive = $location->is_active === null || (bool) $location->is_active;

        if (! $isActive) {
            return false;
        }

        $allowedLocationIds = RemoteAttendanceSettings::allowedWorkLocationIds();

        if (in_array((int) $location->getKey(), $allowedLocationIds, true)) {
            return true;
        }

        $allowedTypes = RemoteAttendanceSettings::allowedLocationTypes();
        $typeValue = $location->location_type?->value ?? (string) $location->location_type;

        return in_array($typeValue, $allowedTypes, true);
    }

    public function employeeTimezone(Employee $employee): string
    {
        return $employee->time_zone ?: ($employee->calendar?->timezone ?: config('app.timezone'));
    }

    public function employeeToday(Employee $employee, ?Carbon $nowUtc = null): string
    {
        return ($nowUtc ?? Carbon::now('UTC'))
            ->copy()
            ->setTimezone($this->employeeTimezone($employee))
            ->toDateString();
    }

    public function workDateFor(Employee $employee, ?Carbon $nowUtc = null): string
    {
        $moment = ($nowUtc ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');

        return app(ShiftWindowResolver::class)->matchPunchToWindow($employee, $moment)['work_date'];
    }

    public function todayAttendance(Employee $employee, ?Carbon $nowUtc = null): ?Attendance
    {
        $workDate = $this->workDateFor($employee, $nowUtc);

        return Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', $workDate)
            ->orderByDesc('check_in')
            ->orderByDesc('id')
            ->first();
    }

    public function checkIn(Employee $employee, ?string $planNote = null, ?Carbon $nowUtc = null): Attendance
    {
        return DB::transaction(function () use ($employee, $planNote, $nowUtc): Attendance {
            $lockedEmployee = Employee::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->with([
                    'workLocation' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class),
                    'calendar'     => fn ($query) => $query->withoutGlobalScope(CompanyScope::class),
                    'user',
                ])
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isEligible($lockedEmployee)) {
                throw new DomainException(__('remote-attendance::app.exceptions.not-eligible'));
            }

            $moment = ($nowUtc ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
            $workDate = $this->workDateFor($lockedEmployee, $moment);

            if (AttendanceSettings::isLockedDate($workDate)) {
                throw new DomainException(__('remote-attendance::app.exceptions.period-locked'));
            }

            $alreadyExists = Attendance::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('employee_id', $lockedEmployee->getKey())
                ->whereDate('work_date', $workDate)
                ->exists();

            if ($alreadyExists) {
                throw new DomainException(__('remote-attendance::app.exceptions.already-checked-in'));
            }

            $attendance = Attendance::create([
                'employee_id'  => $lockedEmployee->getKey(),
                'work_date'    => $workDate,
                'check_in'     => $moment->format('Y-m-d H:i:s'),
                'check_out'    => null,
                'source'       => self::SOURCE_REMOTE,
                'source_label' => __('remote-attendance::app.source'),
                'company_id'   => $lockedEmployee->company_id,
                'creator_id'   => $lockedEmployee->user_id,
            ]);

            if (filled($planNote)) {
                $this->appendChatterNote($attendance, $lockedEmployee, trim((string) $planNote));
            }

            return $attendance;
        });
    }

    public function checkOut(Employee $employee, ?string $summaryNote = null, ?Carbon $nowUtc = null): Attendance
    {
        return DB::transaction(function () use ($employee, $summaryNote, $nowUtc): Attendance {
            $lockedEmployee = Employee::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->with(['calendar', 'user'])
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $moment = ($nowUtc ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
            $workDate = $this->workDateFor($lockedEmployee, $moment);

            if (AttendanceSettings::isLockedDate($workDate)) {
                throw new DomainException(__('remote-attendance::app.exceptions.period-locked'));
            }

            $attendance = Attendance::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('employee_id', $lockedEmployee->getKey())
                ->where('source', self::SOURCE_REMOTE)
                ->whereNull('check_out')
                ->orderByDesc('check_in')
                ->orderByDesc('id')
                ->first();

            if (! $attendance) {
                throw new DomainException(__('remote-attendance::app.exceptions.no-open-session'));
            }

            $checkIn = Carbon::parse($attendance->getRawOriginal('check_in'), 'UTC');

            if (! Attendance::isValidCheckout($checkIn, $moment)) {
                throw new DomainException(__('remote-attendance::app.exceptions.invalid-checkout', [
                    'hours' => AttendanceSettings::maxShiftHours(),
                ]));
            }

            $attendance->update([
                'check_out' => $moment->format('Y-m-d H:i:s'),
            ]);

            if (filled($summaryNote)) {
                $this->appendChatterNote($attendance, $lockedEmployee, trim((string) $summaryNote));
            }

            return $attendance->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{attendance: Attendance, task: ?Task, timesheet: ?Timesheet, items: list<array{task: ?Task, timesheet: ?Timesheet}>}
     */
    public function logDailyWork(Employee $employee, array $data, ?Carbon $nowUtc = null): array
    {
        $rawItems = is_array($data['items'] ?? null) ? array_values($data['items']) : [$data];

        $items = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));

            if ($description !== '') {
                $item['description'] = $description;
                $items[] = $item;
            }
        }

        if ($items === []) {
            throw new DomainException(__('remote-attendance::app.exceptions.description-required'));
        }

        return DB::transaction(function () use ($employee, $items, $nowUtc): array {
            $attendance = $this->todayAttendance($employee, $nowUtc);

            if (! $attendance) {
                throw new DomainException(__('remote-attendance::app.exceptions.check-in-required'));
            }

            $workDate = $attendance->work_date instanceof Carbon
                ? $attendance->work_date->toDateString()
                : (string) $attendance->getRawOriginal('work_date');

            $processed = [];
            $formattedLines = [];
            $lastTask = null;
            $lastTimesheet = null;

            foreach ($items as $item) {
                $result = $this->persistDailyWorkItem($employee, $workDate, $item);
                $task = $result['task'];
                $timesheet = $result['timesheet'];

                $lastTask = $task ?? $lastTask;
                $lastTimesheet = $timesheet ?? $lastTimesheet;
                $processed[] = $result;

                $formattedLines[] = $this->formatWorkItemHtml($item['description'], $task, $timesheet);
            }

            $htmlBody = count($formattedLines) === 1
                ? '<p>'.$formattedLines[0].'</p>'
                : '<ul><li>'.implode('</li><li>', $formattedLines).'</li></ul>';

            $this->appendChatterHtml(
                $attendance,
                $employee,
                $htmlBody,
                __('remote-attendance::app.daily-log-subject', ['date' => $workDate]),
            );

            return [
                'attendance' => $attendance,
                'task'       => $lastTask,
                'timesheet'  => $lastTimesheet,
                'items'      => $processed,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{task: ?Task, timesheet: ?Timesheet}
     */
    protected function persistDailyWorkItem(Employee $employee, string $workDate, array $item): array
    {
        $task = null;
        $timesheet = null;

        if (! static::projectsAvailable()) {
            return ['task' => null, 'timesheet' => null];
        }

        $description = (string) $item['description'];
        $projectId = ! empty($item['project_id']) ? (int) $item['project_id'] : null;
        $taskId = ! empty($item['task_id']) ? (int) $item['task_id'] : null;
        $newTaskTitle = filled($item['new_task_title'] ?? null) ? trim((string) $item['new_task_title']) : null;
        $taskState = filled($item['task_state'] ?? null) ? (string) $item['task_state'] : null;
        $unitAmount = isset($item['unit_amount']) && is_numeric($item['unit_amount']) ? (float) $item['unit_amount'] : null;

        if ($taskId !== null) {
            $task = Task::withoutGlobalScope(CompanyScope::class)->find($taskId);

            if ($task && $projectId === null) {
                $projectId = $task->project_id;
            }
        } elseif ($newTaskTitle !== null && $projectId !== null) {
            $task = Task::create([
                'title'       => $newTaskTitle,
                'description' => $description,
                'project_id'  => $projectId,
                'state'       => $taskState && TaskState::tryFrom($taskState) !== null
                    ? $taskState
                    : TaskState::IN_PROGRESS->value,
                'stage_id'    => TaskStage::query()->where('project_id', $projectId)->orderBy('sort')->value('id'),
                'company_id'  => $employee->company_id,
                'creator_id'  => $employee->user_id,
                'is_active'   => true,
            ]);

            if ($employee->user_id) {
                $task->users()->syncWithoutDetaching([$employee->user_id]);
            }
        }

        if ($task !== null && $taskState !== null && TaskState::tryFrom($taskState) !== null && $task->wasRecentlyCreated === false) {
            $task->update(['state' => $taskState]);
        }

        if ($projectId !== null && $unitAmount !== null && $unitAmount > 0 && $employee->user_id) {
            $project = Project::withoutGlobalScope(CompanyScope::class)->find($projectId);

            $timesheet = new Timesheet;
            $timesheet->forceFill([
                'type'        => 'projects',
                'name'        => $description,
                'date'        => $workDate,
                'unit_amount' => $unitAmount,
                'project_id'  => $projectId,
                'task_id'     => $task?->getKey(),
                'partner_id'  => $task?->partner_id ?? $project?->partner_id,
                'company_id'  => $employee->company_id ?? $project?->company_id,
                'user_id'     => $employee->user_id,
                'creator_id'  => $employee->user_id,
            ]);
            $timesheet->save();
        }

        return ['task' => $task, 'timesheet' => $timesheet];
    }

    protected function formatWorkItemHtml(string $description, ?Task $task, ?Timesheet $timesheet): string
    {
        $parts = [nl2br(e($description))];

        if ($task !== null) {
            $taskLabel = e($task->title);

            if (class_exists(TaskResource::class) && Route::has(TaskResource::getRouteBaseName().'.view')) {
                $url = e(TaskResource::getUrl('view', ['record' => $task]));
                $taskLabel = '<a href="'.$url.'" class="underline font-medium">'.$taskLabel.'</a>';
            }

            $parts[] = __('remote-attendance::app.daily-log-task-ref', ['task' => $taskLabel]);
        }

        if ($timesheet !== null) {
            $parts[] = __('remote-attendance::app.daily-log-hours-ref', ['hours' => (float) $timesheet->unit_amount]);
        }

        return implode(' — ', $parts);
    }

    protected function appendChatterNote(Attendance $attendance, Employee $employee, string $body, ?string $subject = null): void
    {
        $this->appendChatterHtml($attendance, $employee, '<p>'.nl2br(e($body)).'</p>', $subject);
    }

    protected function appendChatterHtml(Attendance $attendance, Employee $employee, string $htmlBody, ?string $subject = null): void
    {
        $user = $employee->user;

        $attendance->addMessage([
            'type'        => 'comment',
            'is_internal' => true,
            'subject'     => $subject,
            'body'        => $htmlBody,
            'causer_type' => $user?->getMorphClass(),
            'causer_id'   => $user?->getKey(),
            'company_id'  => $attendance->company_id ?? $employee->company_id,
        ]);
    }

    public static function formatAttendanceNotesHtml(Attendance $record): ?string
    {
        $notes = $record->messages
            ->where('type', 'comment')
            ->sortBy('id')
            ->map(fn ($message): string => (string) str((string) $message->body)->sanitizeHtml())
            ->filter(fn (string $html): bool => trim(strip_tags($html)) !== '')
            ->values();

        return $notes->isEmpty() ? null : $notes->implode('');
    }
}
