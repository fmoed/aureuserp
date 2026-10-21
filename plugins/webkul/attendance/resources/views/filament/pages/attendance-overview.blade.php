<x-filament-panels::page>
    @php
        $totalsByKey = collect($this->getSummaryTotals())->keyBy('key');
        $legend = $this->getLegend();
        $trend = $this->registerView === 'days' ? $this->getDailyTrend() : [];
        $maxDaily = collect($trend)->max(fn (array $day): int => $day['present'] + $day['absent'] + $day['leave']) ?: 0;

        $presentCount = (int) ($totalsByKey['present']['value'] ?? 0);
        $absentCount = (int) ($totalsByKey['absent']['value'] ?? 0);
        $leaveCount = (int) ($totalsByKey['on_leave']['value'] ?? 0);
        $pendingCount = (int) ($totalsByKey['pending']['value'] ?? 0);
        $holidaysCount = (int) ($totalsByKey['holidays']['value'] ?? 0);
        $employeesCount = (int) ($totalsByKey['employees']['value'] ?? 0);
        $absenceRate = (float) ($totalsByKey['rate']['value'] ?? 0);

        $totalTrackedDays = max($presentCount + $absentCount + $leaveCount, 1);
        $presentPct = round(($presentCount / $totalTrackedDays) * 100, 1);
        $leavePct = round(($leaveCount / $totalTrackedDays) * 100, 1);
        $absentPct = max(0, round(100 - $presentPct - $leavePct, 1));
    @endphp

    <style>
        .attendance-register-table .fi-reg-tabs {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            border-bottom: 0;
        }

        .attendance-register-table .fi-ta-ctn {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }

        /* Keep table toolbar dropdowns (Filters, Column Manager) above sticky table headers */
        .attendance-register-table .fi-ta-header-ctn,
        .attendance-register-table .fi-ta-header-toolbar {
            position: relative;
            z-index: 30;
        }

        .attendance-register-table .fi-ta-content-ctn {
            position: relative;
            z-index: 1;
        }

        .attendance-register-table .fi-ta-table {
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
            width: max-content !important;
            min-width: 100%;
            border-inline-start: 1px solid rgb(229 231 235);
            border-top: 1px solid rgb(229 231 235);
            background-color: #ffffff;
        }

        .dark .attendance-register-table .fi-ta-table {
            border-inline-start-color: rgb(255 255 255 / 0.1);
            border-top-color: rgb(255 255 255 / 0.1);
            background-color: #111827;
        }

        /* Excel grid lines: every cell gets clear separating lines for both column and row */
        .attendance-register-table .fi-ta-table th,
        .attendance-register-table .fi-ta-table td {
            border-inline-end: 1px solid rgb(229 231 235);
            border-bottom: 1px solid rgb(229 231 235);
            transition: background-color 0.1s ease;
        }

        .dark .attendance-register-table .fi-ta-table th,
        .dark .attendance-register-table .fi-ta-table td {
            border-inline-end-color: rgb(255 255 255 / 0.08);
            border-bottom-color: rgb(255 255 255 / 0.08);
        }

        /* Dynamic Excel-density rows: natural height so any cell needing more height auto-expands the entire row */
        .attendance-register-table .fi-ta-table tbody tr {
            height: auto;
        }

        .attendance-register-table .fi-ta-table td {
            vertical-align: middle !important;
            padding-block: 0 !important;
            height: auto;
        }

        /* Compact padding across all text columns inside register table */
        .attendance-register-table .fi-ta-text:not(.fi-inline) {
            padding-block: 0.25rem !important;
            padding-inline: 0.5rem !important;
            min-height: unset !important;
            line-height: 1.25 !important;
        }

        /* Excel-style table header */
        .attendance-register-table .fi-ta-table thead tr {
            background-color: #f8fafc;
        }

        .dark .attendance-register-table .fi-ta-table thead tr {
            background-color: #18202e;
        }

        .attendance-register-table .fi-ta-table thead th,
        .attendance-register-table .fi-ta-header-cell {
            font-weight: 600;
            border-bottom: 2px solid rgb(209 213 219);
            vertical-align: middle !important;
            padding-block: 0.375rem !important;
        }

        .dark .attendance-register-table .fi-ta-table thead th,
        .dark .attendance-register-table .fi-ta-header-cell {
            border-bottom: 2px solid rgb(255 255 255 / 0.15);
        }

        /* Excel-style row hover tracking: highlights entire row across all days */
        .attendance-register-table .fi-ta-table tbody tr:hover td {
            background-color: #f1f5f9;
        }

        .dark .attendance-register-table .fi-ta-table tbody tr:hover td {
            background-color: rgb(255 255 255 / 0.04);
        }

        /* Excel "Freeze Pane": sticky employee column with solid opaque background and prominent dividing border */
        .attendance-register-table .fi-reg-sticky {
            position: sticky;
            inset-inline-start: 0;
            z-index: 2;
            width: 180px;
            min-width: 180px;
            max-width: 180px;
            background-color: #ffffff;
            border-inline-end: 2px solid rgb(209 213 219) !important;
        }

        [dir="rtl"] .attendance-register-table .fi-reg-sticky {
            right: 0;
            left: auto;
            box-shadow: -4px 0 8px -2px rgba(0, 0, 0, 0.08);
        }

        [dir="ltr"] .attendance-register-table .fi-reg-sticky {
            left: 0;
            right: auto;
            box-shadow: 4px 0 8px -2px rgba(0, 0, 0, 0.08);
        }

        .attendance-register-table th.fi-reg-sticky {
            z-index: 3;
            background-color: #f1f5f9;
        }

        /* Dark mode frozen employee column */
        .dark .attendance-register-table .fi-reg-sticky {
            background-color: #111827;
            border-inline-end-color: rgb(255 255 255 / 0.18) !important;
        }

        .dark .attendance-register-table th.fi-reg-sticky {
            background-color: #1e2638;
            background-color: color-mix(in srgb, #111827 92%, white 8%);
        }

        /* Row hover on frozen column */
        .attendance-register-table .fi-ta-table tbody tr:hover td.fi-reg-sticky {
            background-color: #e2e8f0;
        }

        .dark .attendance-register-table .fi-ta-table tbody tr:hover td.fi-reg-sticky {
            background-color: #1a2232;
        }

        /* Bidirectional employee name truncation: always show the start (first names) of both Arabic and English names */
        .attendance-register-table td.fi-reg-sticky .fi-ta-col,
        .attendance-register-table td.fi-reg-sticky .fi-ta-text,
        .attendance-register-table td.fi-reg-sticky .fi-ta-text-item,
        .attendance-register-table td.fi-reg-sticky .fi-ta-text-item-label {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }

        .attendance-register-table td.fi-reg-sticky.fi-reg-name-rtl .fi-ta-text,
        .attendance-register-table td.fi-reg-sticky.fi-reg-name-rtl .fi-ta-text-item,
        .attendance-register-table td.fi-reg-sticky.fi-reg-name-rtl .fi-ta-text-item-label {
            direction: rtl !important;
            text-align: right !important;
        }

        .attendance-register-table td.fi-reg-sticky.fi-reg-name-ltr .fi-ta-text,
        .attendance-register-table td.fi-reg-sticky.fi-reg-name-ltr .fi-ta-text-item,
        .attendance-register-table td.fi-reg-sticky.fi-reg-name-ltr .fi-ta-text-item-label {
            direction: ltr !important;
            text-align: left !important;
        }

        /* Mobile optimization for frozen employee column */
        @media (max-width: 640px) {
            .attendance-register-table .fi-reg-sticky {
                width: 110px !important;
                min-width: 110px !important;
                max-width: 110px !important;
                padding-inline: 0.5rem !important;
            }
        }

        /* Day column headers: 2-line wrapped text (day name above, date number below) */
        .attendance-register-table .fi-reg-day-header {
            width: 2.75rem !important;
            min-width: 2.75rem !important;
            max-width: 2.75rem !important;
            font-size: 0.6875rem !important;
            line-height: 1.15 !important;
            text-align: center !important;
            padding-inline: 0.125rem !important;
            padding-block: 0.375rem !important;
            white-space: normal !important;
            word-break: normal !important;
            overflow-wrap: normal !important;
            overflow: hidden !important;
            vertical-align: middle !important;
        }

        /* Day cells: compact and centered */
        .attendance-register-table .fi-reg-day-cell {
            width: 2.75rem !important;
            min-width: 2.75rem !important;
            max-width: 2.75rem !important;
            padding-inline: 0 !important;
            padding-block: 0 !important;
            text-align: center !important;
            vertical-align: middle !important;
        }

        .attendance-register-table .fi-reg-day-cell .fi-ta-text:not(.fi-inline) {
            padding-block: 0.25rem !important;
            padding-inline: 0.125rem !important;
            text-align: center !important;
            font-weight: 600;
        }

        /* Chart status colors: explicit and immune to Tailwind purging */
        .fi-chart-track {
            background-color: rgba(229, 231, 235, 0.6);
        }
        .dark .fi-chart-track {
            background-color: rgba(255, 255, 255, 0.06);
        }
        .fi-bar-present, .fi-dot-present {
            background-color: #10b981 !important;
        }
        .fi-bar-leave, .fi-dot-leave {
            background-color: #0ea5e9 !important;
        }
        .fi-bar-absent, .fi-dot-absent {
            background-color: #f43f5e !important;
        }

        /* Auto-fit responsive KPI grid: fills screen width naturally across all displays */
        .fi-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.75rem;
        }

        @media (max-width: 520px) {
            .fi-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.5rem;
            }
        }
    </style>

    {{-- Compact Responsive KPI Summary Strip (4 Balanced Cards) --}}
    <div class="fi-kpi-grid">
        {{-- Employees --}}
        <div class="flex items-center gap-3 rounded-xl border border-gray-200/80 bg-white p-3 shadow-2xs dark:border-white/10 dark:bg-gray-900 sm:p-3.5">
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl sm:h-12 sm:w-12"
                style="background-color: rgba(107, 114, 128, 0.12); color: #64748b;"
            >
                <x-filament::icon icon="heroicon-s-user-group" class="h-6 w-6 text-gray-600 dark:text-gray-300 sm:h-6.5 sm:w-6.5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ $totalsByKey['employees']['label'] ?? __('attendance::filament/pages/overview.grid.totals.employees') }}
                </div>
                <div class="mt-0.5 flex items-baseline gap-1.5">
                    <span class="text-xl font-bold tabular-nums text-gray-950 dark:text-white sm:text-2xl">
                        {{ number_format($employeesCount) }}
                    </span>
                    <span class="hidden truncate text-[10px] text-gray-400 xl:inline">
                        {{ $this->appliedPeriodLabel() }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Present Days --}}
        <div class="flex items-center gap-3 rounded-xl border border-gray-200/80 bg-white p-3 shadow-2xs dark:border-white/10 dark:bg-gray-900 sm:p-3.5">
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl sm:h-12 sm:w-12"
                style="background-color: rgba(16, 185, 129, 0.14); color: #059669;"
            >
                <x-filament::icon icon="heroicon-s-check-circle" class="h-6 w-6 text-emerald-600 dark:text-emerald-400 sm:h-6.5 sm:w-6.5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ $totalsByKey['present']['label'] ?? __('attendance::filament/pages/overview.grid.totals.present') }}
                </div>
                <div class="mt-0.5 flex items-baseline gap-1.5">
                    <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400 sm:text-2xl">
                        {{ number_format($presentCount) }}
                    </span>
                    <span class="text-[11px] font-medium tabular-nums text-emerald-600/80 dark:text-emerald-400/80">
                        {{ $presentPct }}%
                    </span>
                </div>
            </div>
        </div>

        {{-- Absent Days (+ Absence Rate) --}}
        <div class="flex items-center gap-3 rounded-xl border border-gray-200/80 bg-white p-3 shadow-2xs dark:border-white/10 dark:bg-gray-900 sm:p-3.5">
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl sm:h-12 sm:w-12"
                style="background-color: rgba(244, 63, 94, 0.14); color: #e11d48;"
            >
                <x-filament::icon icon="heroicon-s-x-circle" class="h-6 w-6 text-rose-600 dark:text-rose-400 sm:h-6.5 sm:w-6.5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ $totalsByKey['absent']['label'] ?? __('attendance::filament/pages/overview.grid.totals.absent') }}
                </div>
                <div class="mt-0.5 flex items-baseline gap-1.5">
                    <span class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400 sm:text-2xl">
                        {{ number_format($absentCount) }}
                    </span>
                    <span class="text-[11px] font-medium tabular-nums text-rose-600/80 dark:text-rose-400/80">
                        {{ $absenceRate }}%
                    </span>
                    @if ($pendingCount > 0)
                        <span class="rounded-md bg-amber-50 px-1 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            {{ $totalsByKey['pending']['label'] ?? __('attendance::filament/pages/overview.grid.totals.pending') }}: {{ number_format($pendingCount) }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Leave Days --}}
        <div class="flex items-center gap-3 rounded-xl border border-gray-200/80 bg-white p-3 shadow-2xs dark:border-white/10 dark:bg-gray-900 sm:p-3.5">
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl sm:h-12 sm:w-12"
                style="background-color: rgba(14, 165, 233, 0.14); color: #0284c7;"
            >
                <x-filament::icon icon="heroicon-s-calendar-days" class="h-6 w-6 text-sky-600 dark:text-sky-400 sm:h-6.5 sm:w-6.5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ $totalsByKey['on_leave']['label'] ?? __('attendance::filament/pages/overview.grid.totals.on-leave') }}
                </div>
                <div class="mt-0.5 flex items-baseline gap-1.5">
                    <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400 sm:text-2xl">
                        {{ number_format($leaveCount) }}
                    </span>
                    @if ($holidaysCount > 0)
                        <span class="rounded-md bg-indigo-50 px-1 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                            {{ $totalsByKey['holidays']['label'] ?? __('attendance::filament/pages/overview.grid.totals.holidays') }}: {{ number_format($holidaysCount) }}
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Collapsible Daily Attendance Trend Chart (Day Register Mode) --}}
    @if ($this->registerView === 'days' && $maxDaily > 0)
        <div
            x-data="{ showChart: true }"
            class="attendance-daily-trend rounded-xl border border-gray-200/80 bg-white px-4 py-3 shadow-2xs dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                        {{ __('attendance::filament/pages/overview.grid.chart') }}
                    </span>
                    <span class="hidden text-[11px] text-gray-500 sm:inline dark:text-gray-400">
                        — {{ __('attendance::filament/pages/overview.grid.pending-note') }}
                    </span>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-3 text-[11px] font-medium text-gray-600 dark:text-gray-400">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="fi-dot-present h-2.5 w-2.5 rounded-full" style="background-color: #10b981;"></span>
                            {{ __('attendance::filament/pages/overview.statuses.present') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="fi-dot-absent h-2.5 w-2.5 rounded-full" style="background-color: #f43f5e;"></span>
                            {{ __('attendance::filament/pages/overview.statuses.absent') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="fi-dot-leave h-2.5 w-2.5 rounded-full" style="background-color: #0ea5e9;"></span>
                            {{ __('attendance::filament/pages/overview.statuses.on-leave') }}
                        </span>
                    </div>

                    <button
                        type="button"
                        x-on:click="showChart = !showChart"
                        class="inline-flex items-center justify-center rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                    >
                        <x-filament::icon
                            icon="heroicon-m-chevron-up"
                            class="h-4 w-4 transition-transform duration-150"
                            x-bind:class="{ 'rotate-180': !showChart }"
                        />
                    </button>
                </div>
            </div>

            <div x-show="showChart" x-collapse class="mt-3 overflow-x-auto">
                <div class="flex min-w-max items-end gap-1 sm:min-w-0 sm:gap-1.5">
                    @foreach ($trend as $day)
                        @php
                            $dayTotal = $day['present'] + $day['absent'] + $day['leave'];
                            $dayNumber = \Illuminate\Support\Carbon::parse($day['date'])->format('j');

                            if ($dayTotal > 0) {
                                $dayPresentPct = round(($day['present'] / $dayTotal) * 100, 1);
                                $dayLeavePct = round(($day['leave'] / $dayTotal) * 100, 1);
                                $dayAbsentPct = max(0, round(100 - $dayPresentPct - $dayLeavePct, 1));
                            } else {
                                $dayPresentPct = 0;
                                $dayLeavePct = 0;
                                $dayAbsentPct = 0;
                            }

                            $tooltipText = sprintf(
                                '%s — %s: %d (%s%%) | %s: %d (%s%%) | %s: %d (%s%%)',
                                $day['label'],
                                __('attendance::filament/pages/overview.statuses.present'),
                                $day['present'],
                                $dayPresentPct,
                                __('attendance::filament/pages/overview.statuses.absent'),
                                $day['absent'],
                                $dayAbsentPct,
                                __('attendance::filament/pages/overview.statuses.on-leave'),
                                $day['leave'],
                                $dayLeavePct,
                            );
                        @endphp
                        <div
                            class="group flex min-w-[1.25rem] flex-1 flex-col items-center gap-1"
                            x-data
                            x-tooltip.raw="{{ $tooltipText }}"
                        >
                            <div class="fi-chart-track flex h-16 w-full flex-col justify-end overflow-hidden rounded-t transition-all group-hover:ring-1 group-hover:ring-primary-500/50">
                                @if ($dayTotal > 0)
                                    <div class="flex h-full w-full flex-col justify-end overflow-hidden rounded-t-xs">
                                        @if ($day['absent'] > 0)
                                            <div
                                                class="fi-bar-absent w-full transition-all"
                                                style="background-color: #f43f5e; height: {{ $dayAbsentPct }}%;"
                                            ></div>
                                        @endif
                                        @if ($day['leave'] > 0)
                                            <div
                                                class="fi-bar-leave w-full transition-all"
                                                style="background-color: #0ea5e9; height: {{ $dayLeavePct }}%;"
                                            ></div>
                                        @endif
                                        @if ($day['present'] > 0)
                                            <div
                                                class="fi-bar-present w-full transition-all"
                                                style="background-color: #10b981; height: {{ $dayPresentPct }}%;"
                                            ></div>
                                        @endif
                                    </div>
                                @else
                                    <div class="my-auto mx-auto h-1 w-2 rounded-full bg-gray-300 dark:bg-white/10"></div>
                                @endif
                            </div>
                            <span class="text-[10px] font-medium tabular-nums text-gray-500 group-hover:text-gray-900 dark:text-gray-400 dark:group-hover:text-white">
                                {{ $dayNumber }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="attendance-register-table">
        <x-filament::tabs class="fi-reg-tabs w-full">
            <x-filament::tabs.item
                :active="$this->registerView === 'days'"
                icon="heroicon-m-table-cells"
                wire:click="showRegisterView('days')"
            >
                {{ __('attendance::filament/pages/overview.table.view.days') }}
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$this->registerView === 'totals'"
                icon="heroicon-m-squares-2x2"
                wire:click="showRegisterView('totals')"
            >
                {{ __('attendance::filament/pages/overview.table.view.totals') }}
            </x-filament::tabs.item>

            @if ($this->registerView === 'days')
                <div class="ms-auto hidden flex-wrap items-center gap-1.5 pe-2 lg:flex">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ __('attendance::filament/pages/overview.grid.legend') }}:
                    </span>
                    @foreach ($legend as $status)
                        @if ($status['key'] !== \Webkul\Attendance\Services\AttendanceOverviewService::STATUS_FUTURE && ! empty($status['label']))
                            <x-filament::badge :color="$status['color']" size="sm">
                                {{ \Webkul\Attendance\Filament\Tables\AttendanceRegisterTable::statusShortLabel($status['key']) }}: {{ $status['label'] }}
                            </x-filament::badge>
                        @endif
                    @endforeach
                </div>
            @endif
        </x-filament::tabs>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
