<x-filament-panels::page>
    @php
        $summary = $this->getSummaryTotals();
        $legend = $this->getLegend();
        $trend = $this->rangeMode !== 'year' ? $this->getDailyTrend() : [];

        $valueColor = fn (string $color): string => match ($color) {
            'success' => 'text-success-600 dark:text-success-400',
            'danger'  => 'text-danger-600 dark:text-danger-400',
            'info'    => 'text-info-600 dark:text-info-400',
            'warning' => 'text-warning-600 dark:text-warning-400',
            'primary' => 'text-primary-600 dark:text-primary-400',
            default   => 'text-gray-900 dark:text-white',
        };
    @endphp

    {{-- شريط ملخص مضغوط: الأرقام الأساسية الأربعة فقط (الموظفون/الحضور/الغياب/النسبة)
        والقيم الثانوية متاحة في وضع الملخص والتصدير — الرقم بارز ملون
        والتسمية صغيرة خلفه، بدل سبع بطاقات/سبع فقرات نصية تستهلك الرأس. --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-gray-200/80 bg-white px-4 py-2 dark:border-white/10 dark:bg-gray-900">
        @foreach ($summary as $item)
            @if (in_array($item['key'], ['employees', 'present', 'absent', 'rate'], true))
                @php
                    $isRate = $item['key'] === 'rate';
                    $valueDisplay = $isRate ? $item['value'].'%' : number_format($item['value']);
                @endphp
                <span
                    class="flex items-baseline gap-1 whitespace-nowrap"
                    title="{{ $item['label'] }}: {{ $valueDisplay }}"
                >
                    <span class="text-sm font-bold {{ $valueColor($item['color'] ?? 'gray') }}">
                        {{ $valueDisplay }}
                    </span>
                    <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400">
                        {{ $item['label'] }}
                    </span>
                </span>
            @endif
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

    {{-- ملاحظة الإجازة المعلقة: سطر خفيف بلا حدود تحت الشريط --}}
    <div class="flex items-center gap-1.5 px-1 text-[11px] text-gray-500 dark:text-gray-400">
        <x-filament::icon icon="heroicon-m-information-circle" class="h-3.5 w-3.5 shrink-0 text-warning-500" />
        <span>{{ __('attendance::filament/pages/overview.grid.pending-note') }}</span>
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
                        <div class="rounded-t bg-danger-500" style="flex: {{ $day['absent'] }} 0 0"></div>
                        <div class="rounded-t bg-info-500" style="flex: {{ $day['leave'] }} 0 0"></div>
                        <div class="rounded-t bg-success-500" style="flex: {{ $day['present'] }} 0 0"></div>
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

    {{-- مفتاح عرض الجدول: ملخص الموظفين أو السجل اليومي (مخفية في وضع السنة: ملخص فقط)
        مع شرائح الفلاتر المطبقة مرئية دائماً حتى مع طي قسم التصفية --}}
    <div class="flex flex-wrap items-center justify-between gap-2">
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

        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                {{ __('attendance::filament/pages/overview.filter-chips.period') }}:
                <span class="font-semibold">{{ $this->appliedPeriodLabel() }}</span>
            </span>

            @if ($appliedEmployee = $this->appliedEmployeeName())
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    {{ __('attendance::filament/pages/overview.filter-chips.employee') }}:
                    <span class="font-semibold">{{ $appliedEmployee }}</span>
                    <button
                        type="button"
                        wire:click="clearEmployee"
                        class="-me-1 rounded-full p-0.5 text-gray-400 transition hover:bg-danger-100 hover:text-danger-600 dark:hover:bg-danger-500/20"
                        title="{{ __('attendance::filament/pages/overview.filter-chips.clear') }}"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-3.5 w-3.5" />
                    </button>
                </span>
            @endif
        </div>
    </div>

    {{-- جدول السجل: تخطيط ثابت مع العروض الصريحة للأعمدة، لأن table-auto
        يوزع المساحة الفارغة على كل الأعمدة فيتمدد الرقم بعيدًا عن ترويسته.
        عمود الموظف مثبت أفقياً (RTL/LTR عبر الخصائص المنطقية) حتى لا
        يضيع سياق الصف أثناء تمرير أعمدة الأيام. --}}
    <style>
        .attendance-register-table .fi-ta-table {
            table-layout: fixed;
            /* WebKit (iOS Safari) لا يرسم خلفية الخلايا اللاصقة مع border-collapse:
               collapse فتظهر أسماء الأعمدة من خلال عمود الموظف أثناء التمرير —
               separate مع فواصل الصفوف القائمة على divide يرسم الخلفية صحيحاً */
            border-collapse: separate;
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
