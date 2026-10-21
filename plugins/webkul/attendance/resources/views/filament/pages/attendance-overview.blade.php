<x-filament-panels::page>
    @php
        $summary = $this->getSummaryTotals();
        $legend = $this->getLegend();
        $trend = $this->rangeMode !== 'year' ? $this->getDailyTrend() : [];

        $valueColor = fn (string $color): string => match ($color) {
            'success' => 'text-emerald-600 dark:text-emerald-400',
            'danger'  => 'text-rose-600 dark:text-rose-400',
            'info'    => 'text-sky-600 dark:text-sky-400',
            'warning' => 'text-amber-600 dark:text-amber-400',
            'primary' => 'text-indigo-600 dark:text-indigo-400',
            default   => 'text-gray-900 dark:text-white',
        };
    @endphp

    {{-- شريط ملخص مضغوط في سطر واحد: الأرقام الأساسية (الموظفون/الحضور/الغياب/النسبة)
        ظاهرة دائماً، والثانوية (إجازة/معلق/عطلات) تظهر من sm فأعلى، ودليل
        الحالات من md فأعلى — بدل سبع بطاقات تأكل رأس الصفحة خاصة على الهاتف. --}}
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-2.5 dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5">
            @foreach ($summary as $item)
                @php
                    $isSecondary = in_array($item['key'], ['on_leave', 'pending', 'holidays'], true);
                    $isRate = $item['key'] === 'rate';
                    $valueDisplay = $isRate ? $item['value'].'%' : number_format($item['value']);
                @endphp
                <span
                    class="flex items-baseline gap-1.5 whitespace-nowrap {{ $isSecondary ? 'hidden sm:inline-flex' : '' }}"
                    title="{{ $item['label'] }}: {{ $valueDisplay }}"
                >
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ $item['label'] }}
                    </span>
                    <span class="text-base font-bold {{ $valueColor($item['color'] ?? 'gray') }}">
                        {{ $valueDisplay }}
                    </span>
                </span>
            @endforeach

            @if ($this->rangeMode !== 'year')
                <span class="ms-auto hidden flex-wrap items-center gap-1.5 md:flex">
                    <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">
                        {{ __('attendance::filament/pages/overview.grid.legend') }}:
                    </span>
                    @foreach ($legend as $status)
                        @if ($status['key'] !== \Webkul\Attendance\Services\AttendanceOverviewService::STATUS_FUTURE && ! empty($status['label']))
                            <x-filament::badge :color="$status['color']" size="sm">
                                {{ $status['label'] }}
                            </x-filament::badge>
                        @endif
                    @endforeach
                </span>
            @endif
        </div>

        <div class="mt-1.5 flex items-center gap-1.5 border-t border-gray-100 pt-1.5 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
            <x-filament::icon icon="heroicon-m-information-circle" class="h-4 w-4 shrink-0 text-amber-500" />
            <span>{{ __('attendance::filament/pages/overview.grid.pending-note') }}</span>
        </div>
    </div>

    {{-- الرسم اليومي: بيانات موجودة أصلاً في الصفحة (getDailyTrend)، مطوي
        افتراضياً حتى لا يستهلك مساحة رأس الصفحة، CSS خالص بلا مكتبات --}}
    @if (count($trend) > 0)
        <details class="attendance-trend group rounded-xl border border-gray-200/80 bg-white dark:border-white/10 dark:bg-gray-900">
            <summary class="flex cursor-pointer list-none select-none items-center gap-2 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-200 [&::-webkit-details-marker]:hidden">
                <x-filament::icon icon="heroicon-m-chart-bar" class="h-4 w-4 text-primary-500" />
                {{ __('attendance::filament/pages/overview.grid.chart') }}
                <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-400 transition-transform group-open:rotate-180" />
            </summary>

            <div class="attendance-daily-trend flex items-end gap-0.5 overflow-x-auto px-4 pb-4 pt-1">
                @foreach ($trend as $day)
                    <div
                        class="flex h-24 min-w-[1.25rem] flex-1 flex-col justify-end gap-px"
                        title="{{ $day['label'] }} ·
                            {{ __('attendance::filament/pages/overview.grid.totals.present') }}: {{ $day['present'] }},
                            {{ __('attendance::filament/pages/overview.grid.totals.absent') }}: {{ $day['absent'] }},
                            {{ __('attendance::filament/pages/overview.grid.totals.on-leave') }}: {{ $day['leave'] }}"
                    >
                        <div class="rounded-t bg-rose-500" style="flex: {{ $day['absent'] }} 0 0"></div>
                        <div class="rounded-t bg-sky-500" style="flex: {{ $day['leave'] }} 0 0"></div>
                        <div class="rounded-t bg-emerald-500" style="flex: {{ $day['present'] }} 0 0"></div>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    {{-- تصفية السجل --}}
    <x-filament::section :heading="__('attendance::filament/pages/overview.form.heading')" :collapsible="true">
        {{ $this->filterForm }}

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <x-filament::button wire:click="applyFilters" icon="heroicon-m-funnel">
                {{ __('attendance::filament/pages/overview.form.apply') }}
            </x-filament::button>

            <x-filament::button
                wire:click="resetFilters"
                color="gray"
                outlined
            >
                {{ __('attendance::filament/pages/overview.form.reset') }}
            </x-filament::button>
        </div>
    </x-filament::section>

    {{-- مفتاح عرض الجدول: ملخص الموظفين أو السجل اليومي (مخفية في وضع السنة: ملخص فقط) --}}
    @if ($this->rangeMode !== 'year')
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::button
                size="sm"
                wire:click="showRegisterView('totals')"
                :color="$this->registerView === 'totals' ? 'primary' : 'gray'"
                :outlined="$this->registerView !== 'totals'"
                icon="heroicon-m-squares-2x2"
            >
                {{ __('attendance::filament/pages/overview.table.view.totals') }}
            </x-filament::button>

            <x-filament::button
                size="sm"
                wire:click="showRegisterView('days')"
                :color="$this->registerView === 'days' ? 'primary' : 'gray'"
                :outlined="$this->registerView !== 'days'"
                icon="heroicon-m-table-cells"
            >
                {{ __('attendance::filament/pages/overview.table.view.days') }}
            </x-filament::button>
        </div>
    @endif

    {{-- جدول السجل: تخطيط ثابت مع العروض الصريحة للأعمدة، لأن table-auto
        يوزع المساحة الفارغة على كل الأعمدة فيتمدد الرقم بعيدًا عن ترويسته.
        عمود الموظف مثبت أفقياً (RTL/LTR عبر الخصائص المنطقية) حتى لا
        يضيع سياق الصف أثناء تمرير أعمدة الأيام. --}}
    <style>
        .attendance-register-table .fi-ta-table {
            table-layout: fixed;
        }

        .attendance-register-table .fi-reg-sticky {
            position: sticky;
            inset-inline-start: 0;
            z-index: 1;
            border-inline-end: 1px solid rgb(229 231 235);
            background: #fff;
        }

        .attendance-register-table th.fi-reg-sticky {
            z-index: 2;
            background: #f9fafb;
        }

        .dark .attendance-register-table .fi-reg-sticky {
            background: #111827;
            border-inline-end-color: rgb(255 255 255 / 0.1);
        }

        .dark .attendance-register-table th.fi-reg-sticky {
            background: rgb(255 255 255 / 0.05);
        }
    </style>
    <div class="attendance-register-table">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
