<?php

namespace Webkul\Attendance\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Attendance\Enums\PairingMode;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Rows are a projection of events: for one employee and one writer slug,
 * the rows of the affected time window are rebuilt from the events and
 * reconciled in place. Re-running with the same events yields the same
 * rows. Manual rows are never read or written here.
 */
class EventPairingService
{
    /**
     * Punches closer than this to the previously accepted punch are
     * accidental double scans: linked to the session, never moving it.
     */
    public const DUPLICATE_PUNCH_SECONDS = 120;

    private ?int $duplicatePunchSeconds = null;

    /**
     * @return array{events: int, rows: list<int>}
     */
    public function rebuild(Employee $employee, string $writer): array
    {
        return DB::transaction(function () use ($employee, $writer): array {
            $resolver = app(ShiftWindowResolver::class);
            $lockDate = AttendanceSettings::lockDate()?->toDateString();

            $pending = $this->writerEvents($employee, $writer, fn (Builder $query) => $query->whereNull('attendance_id'))
                ->reject(fn (AttendanceEvent $event): bool => $lockDate !== null
                    && $this->workDateFor($resolver, $employee, $event) <= $lockDate)
                ->values();

            if ($pending->isEmpty()) {
                return ['events' => 0, 'rows' => []];
            }

            $earliestPending = $pending->first()->getRawOriginal('punched_at');

            $windowStart = Carbon::parse($earliestPending, 'UTC')
                ->subHours(AttendanceSettings::maxShiftHours())
                ->format('Y-m-d H:i:s');

            $touching = $this->writerRows($employee, $writer, fn (Builder $query) => $query->where(function (Builder $query) use ($windowStart): void {
                $query->where('check_out', '>=', $windowStart)
                    ->orWhere('check_in', '>=', $windowStart);
            }))
                ->reject(fn (Attendance $row): bool => $lockDate !== null && $row->work_date->toDateString() <= $lockDate);

            $rebuildFrom = $touching
                ->map(fn (Attendance $row): string => $row->getRawOriginal('check_in'))
                ->push($earliestPending)
                ->min();

            $rows = $this->writerRows($employee, $writer, fn (Builder $query) => $query->where('check_in', '>=', $rebuildFrom))
                ->reject(fn (Attendance $row): bool => $lockDate !== null && $row->work_date->toDateString() <= $lockDate)
                ->values();

            $rowIds = $rows->modelKeys();

            $events = $this->writerEvents($employee, $writer, fn (Builder $query) => $query->where(function (Builder $query) use ($rowIds): void {
                $query->whereNull('attendance_id')
                    ->orWhereIn('attendance_id', $rowIds);
            }))
                ->reject(fn (AttendanceEvent $event): bool => $lockDate !== null
                    && $this->workDateFor($resolver, $employee, $event) <= $lockDate)
                ->values();

            return [
                'events' => $pending->count(),
                'rows'   => $this->reconcile($employee, $rows, $this->sessionize($employee, $events)),
            ];
        });
    }

    protected function workDateFor(ShiftWindowResolver $resolver, Employee $employee, AttendanceEvent $event): string
    {
        return $resolver->matchPunchToWindow(
            $employee,
            Carbon::parse($event->getRawOriginal('punched_at'), 'UTC'),
        )['work_date'];
    }

    /**
     * @param  Collection<int, AttendanceEvent>  $events
     * @return list<array{check_in: Carbon, check_out: ?Carbon, events: list<AttendanceEvent>}>
     */
    protected function duplicatePunchSeconds(): int
    {
        return $this->duplicatePunchSeconds ??= AttendanceSettings::duplicatePunchWindowSeconds();
    }

    /**
     * Re-evaluate automatic rows in a date range from their raw events,
     * applying the current shift rules (used after a shift change). Manual
     * rows and locked periods are never touched.
     *
     * @return array{rows: int}
     */
    public function rebuildForRange(Employee $employee, string $writer, Carbon $from, Carbon $to): array
    {
        return DB::transaction(function () use ($employee, $writer, $from, $to): array {
            $lockDate = AttendanceSettings::lockDate();

            $from = $from->copy()->startOfDay();
            $to = $to->copy()->endOfDay();

            if ($lockDate !== null && $from->lte($lockDate)) {
                $from = $lockDate->copy()->addDay()->startOfDay();
            }

            if ($from->gt($to)) {
                return ['rows' => 0];
            }

            $rows = Attendance::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('employee_id', $employee->getKey())
                ->where('source', '!=', Attendance::SOURCE_MANUAL)
                ->where(fn (Builder $query) => $query
                    ->where('source', $writer)
                    ->orWhere('source', 'like', $writer.':%'))
                ->whereDate('work_date', '>=', $from->toDateString())
                ->whereDate('work_date', '<=', $to->toDateString())
                ->get();

            $rowIds = $rows->modelKeys();

            if ($rowIds !== []) {
                AttendanceEvent::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->whereIn('attendance_id', $rowIds)
                    ->update(['attendance_id' => null]);

                $rows->each(fn (Attendance $row): ?bool => $row->delete());
            }

            return ['rows' => count($this->rebuild($employee, $writer)['rows'])];
        });
    }

    /**
     * @param  Collection<int, AttendanceEvent>  $events
     * @return list<array{work_date: string, check_in: Carbon, check_out: ?Carbon, break_minutes: int, events: list<AttendanceEvent>}>
     */
    protected function sessionize(Employee $employee, Collection $events): array
    {
        $resolver = app(ShiftWindowResolver::class);
        $calendar = $resolver->resolveCalendarFor($employee);
        $mode = $calendar?->pairing_mode ?? PairingMode::FirstInLastOut;

        $sessions = [];
        $current = null;
        $lastAccepted = null;

        foreach ($events as $event) {
            $punch = Carbon::parse($event->getRawOriginal('punched_at'), 'UTC');

            if ($current !== null && $lastAccepted !== null && $lastAccepted->diffInSeconds($punch) < $this->duplicatePunchSeconds()) {
                $current['events'][] = $event;
                $current['punches'][] = $punch;

                continue;
            }

            $match = $resolver->matchPunchToWindow($employee, $punch);
            $workDate = $match['work_date'];
            $windowClose = $match['window']['window_close'] ?? null;
            $periodId = $match['window']['period_id'] ?? null;
            $direction = (int) $event->direction;

            if ($current !== null) {
                $currentIsAnchored = $current['window_close'] !== null;
                $newIsAnchored = $windowClose !== null;

                $differentWorkday = $currentIsAnchored && $newIsAnchored && $current['work_date'] !== $workDate;
                $strictIntervalChange = $mode === PairingMode::StrictIntervals
                    && $current['period_id'] !== null
                    && $periodId !== null
                    && $current['period_id'] !== $periodId;
                $pairClosed = $mode === PairingMode::EveryInOutPair && $current['check_out'] !== null;

                // A punch outside its shift window (a weekend scan, or a
                // device clock beyond the window) still obeys the legacy
                // safety cap so a forgotten checkout cannot swallow it.
                $tooFar = (! $currentIsAnchored || ! $newIsAnchored)
                    && ! Attendance::isValidCheckout($current['check_in'], $punch);

                // Outside a shift an explicit out ends the block for good.
                $legacyExplicitIn = ! $currentIsAnchored
                    && $current['closed_by_explicit_out']
                    && $direction === AttendanceEvent::DIRECTION_IN;

                // Inside a shift an out/in pair is a break when it fits the
                // configured break; a longer gap is a second work block.
                $breakAllowance = max(0, (int) ($calendar?->break_duration_minutes ?? 60));
                $longExplicitBreak = $currentIsAnchored
                    && $mode === PairingMode::FirstInLastOut
                    && $current['closed_by_explicit_out']
                    && $direction === AttendanceEvent::DIRECTION_IN
                    && $current['check_out'] !== null
                    && $punch->gt($current['check_out'])
                    && $current['check_out']->diffInMinutes($punch) > $breakAllowance;

                if ($differentWorkday || $strictIntervalChange || $pairClosed || $tooFar || $legacyExplicitIn || $longExplicitBreak) {
                    $sessions[] = $current;
                    $current = null;
                }
            }

            if ($current === null) {
                $current = [
                    'work_date'              => $workDate,
                    'window_close'           => $windowClose,
                    'period_id'              => $periodId,
                    'check_in'               => $punch,
                    'check_out'              => null,
                    'events'                 => [$event],
                    'punches'                => [$punch],
                    'closed_by_explicit_out' => false,
                ];

                $lastAccepted = $punch;

                continue;
            }

            if ($direction === AttendanceEvent::DIRECTION_IN && $current['closed_by_explicit_out']) {
                // Return from a break: keep the same block.
                $current['check_out'] = null;
                $current['closed_by_explicit_out'] = false;
            } elseif ($direction === AttendanceEvent::DIRECTION_OUT) {
                $current['check_out'] = $punch;
                $current['closed_by_explicit_out'] = true;
            } else {
                // Unknown or repeated IN direction: the latest punch is the candidate exit.
                $current['check_out'] = $punch;
            }

            $current['events'][] = $event;
            $current['punches'][] = $punch;
            $lastAccepted = $punch;
        }

        if ($current !== null) {
            $sessions[] = $current;
        }

        return array_map(function (array $session): array {
            $session['break_minutes'] = $this->sessionBreakMinutes($session);

            unset($session['punches'], $session['closed_by_explicit_out'], $session['window_close'], $session['period_id']);

            return $session;
        }, $sessions);
    }

    /**
     * Break minutes inside a single block: explicit OUT→IN gaps when the
     * device reports directions, otherwise the inner punch gaps of an
     * alternating first-in/last-out block.
     *
     * @param  array{punches: list<Carbon>, events: list<AttendanceEvent>}  $session
     */
    protected function sessionBreakMinutes(array $session): int
    {
        $punches = $session['punches'];
        $events = $session['events'];

        if (count($punches) < 3) {
            return 0;
        }

        $break = 0;
        $outAt = null;

        foreach ($punches as $index => $punch) {
            $direction = (int) $events[$index]->direction;

            if ($direction === AttendanceEvent::DIRECTION_OUT) {
                $outAt = $punch;

                continue;
            }

            if ($direction === AttendanceEvent::DIRECTION_IN && $outAt !== null) {
                $break += max(0, (int) round($outAt->diffInMinutes($punch)));
                $outAt = null;
            }
        }

        if ($break > 0) {
            return $break;
        }

        for ($index = 1; $index + 1 < count($punches) - 1; $index += 2) {
            $break += max(0, (int) round($punches[$index]->diffInMinutes($punches[$index + 1])));
        }

        return $break;
    }

    /**
     * @param  Collection<int, Attendance>  $rows
     * @param  list<array{check_in: Carbon, check_out: ?Carbon, events: list<AttendanceEvent>}>  $sessions
     * @return list<int>
     */
    protected function reconcile(Employee $employee, Collection $rows, array $sessions): array
    {
        $rowIds = [];

        foreach ($sessions as $index => $session) {
            $row = $rows->get($index) ?? new Attendance;
            $first = $session['events'][0];
            $checkOut = $session['check_out']?->format('Y-m-d H:i:s');

            if (
                $checkOut === null
                && $row->exists
                && $row->getRawOriginal('check_out') !== null
                && $first->attendance_id !== null
                && (int) $first->attendance_id === (int) $row->getKey()
                && Attendance::isValidCheckout($session['check_in'], Carbon::parse($row->getRawOriginal('check_out'), 'UTC'))
            ) {
                $checkOut = $row->getRawOriginal('check_out');
            }

            $row->fill([
                'employee_id'   => $employee->getKey(),
                'work_date'     => $session['work_date'],
                'check_in'      => $session['check_in']->format('Y-m-d H:i:s'),
                'check_out'     => $checkOut,
                'break_minutes' => (int) ($session['break_minutes'] ?? 0),
                'is_estimated'  => false,
                'source'        => $first->source,
                'source_label'  => $first->source_label,
                'company_id'    => $first->company_id ?? $employee->company_id,
            ]);

            $row->save();

            AttendanceEvent::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->whereKey(array_map(fn (AttendanceEvent $event): int => $event->getKey(), $session['events']))
                ->update(['attendance_id' => $row->getKey()]);

            $rowIds[] = $row->getKey();
        }

        $rows->slice(count($sessions))->each(fn (Attendance $row) => $row->delete());

        return $rowIds;
    }

    /**
     * @param  Closure(Builder): mixed  $constraint
     * @return Collection<int, AttendanceEvent>
     */
    protected function writerEvents(Employee $employee, string $writer, Closure $constraint): Collection
    {
        return AttendanceEvent::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->getKey())
            ->where(fn (Builder $query) => $query
                ->where('source', $writer)
                ->orWhere('source', 'like', $writer.':%'))
            ->tap($constraint)
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Closure(Builder): mixed  $constraint
     * @return Collection<int, Attendance>
     */
    protected function writerRows(Employee $employee, string $writer, Closure $constraint): Collection
    {
        return Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->getKey())
            ->where('source', '!=', Attendance::SOURCE_MANUAL)
            ->where(fn (Builder $query) => $query
                ->where('source', $writer)
                ->orWhere('source', 'like', $writer.':%'))
            ->tap($constraint)
            ->orderBy('check_in')
            ->orderBy('id')
            ->get();
    }
}
