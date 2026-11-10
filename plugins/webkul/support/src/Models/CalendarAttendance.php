<?php

namespace Webkul\Support\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\CalendarAttendanceFactory;

class CalendarAttendance extends Model implements Sortable
{
    use HasFactory, SortableTrait;

    protected $table = 'calendar_attendances';

    protected $fillable = [
        'sort',
        'name',
        'day_of_week',
        'day_period',
        'week_type',
        'display_type',
        'date_from',
        'date_to',
        'hour_from',
        'hour_to',
        'duration_days',
        'calendar_id',
        'creator_id',
        'resource_type',
        'resource_id',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function calendar()
    {
        return $this->belongsTo(Calendar::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Normalize a stored hour value to minutes past midnight.
     *
     * Accepts both historical decimal hours (`8`, `"8.5"`) and clock strings
     * (`"08:30"`, `"08:30:00"`) so legacy rows never crash the calendar math.
     */
    public static function minutesOfDay(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value * 60;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim((string) $value), $matches)) {
            return (float) ((int) $matches[1] * 60 + (int) $matches[2] + ((int) ($matches[3] ?? 0)) / 60);
        }

        return 0.0;
    }

    /**
     * Persist decimal hours but expose the clock value to forms and tables.
     */
    protected static function hourAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?string => $value === null || $value === ''
                ? null
                : static::formatClock(static::minutesOfDay($value)),
            set: fn (mixed $value): ?string => $value === null || $value === ''
                ? null
                : (string) round(static::minutesOfDay($value) / 60, 4),
        );
    }

    protected static function formatClock(float $minutes): string
    {
        $minutes = (int) round($minutes);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    protected function hourFrom(): Attribute
    {
        return static::hourAttribute();
    }

    protected function hourTo(): Attribute
    {
        return static::hourAttribute();
    }

    public static function getWeekType(\DateTime|Carbon $date): int
    {
        return (int) floor(($date->toDateTime()->format('z') + 1) / 7) % 2;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($calendarAttendance) {
            $calendarAttendance->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory(): CalendarAttendanceFactory
    {
        return CalendarAttendanceFactory::new();
    }
}
