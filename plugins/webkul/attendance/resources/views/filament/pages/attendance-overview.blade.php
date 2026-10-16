<x-filament-panels::page>
    @php
        $summary = $this->getSummaryTotals();
        $legend = $this->getLegend();
    @endphp

    {{-- بطاقات الملخص الإحصائي في قمة الصفحة --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem;">
        @foreach ($summary as $item)
            @php
                $color = $item['color'] ?? 'gray';
                $isRate = ($item['key'] ?? '') === 'rate';
                $valueDisplay = $isRate ? $item['value'].'%' : number_format($item['value']);
                $tint = match($color) {
                    'success' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                    'danger'  => 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                    'info'    => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                    'warning' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                    'primary' => 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
                    default   => 'bg-gray-500/10 text-gray-500 dark:text-gray-400',
                };
            @endphp
            <div
                class="flex items-center gap-3 rounded-xl border border-gray-200/80 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
                title="{{ $item['label'] }}: {{ $valueDisplay }}"
            >
                @if (isset($item['icon']))
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $tint }}">
                        <x-filament::icon
                            :icon="$item['icon']"
                            class="h-5 w-5"
                        />
                    </span>
                @endif

                <span class="flex min-w-0 flex-col">
                    <span class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ $item['label'] }}
                    </span>
                    <span class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                        {{ $valueDisplay }}
                    </span>
                </span>
            </div>
        @endforeach
    </div>

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

    {{-- شريط دليل الحالات والملاحظات --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200/60 bg-gray-50/50 px-3.5 py-2 dark:border-white/5 dark:bg-white/5">
        @if ($this->rangeMode !== 'year')
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-gray-700 dark:text-gray-200 me-1">
                    {{ __('attendance::filament/pages/overview.grid.legend') }}:
                </span>
                @foreach ($legend as $status)
                    @if ($status['key'] !== \Webkul\Attendance\Services\AttendanceOverviewService::STATUS_FUTURE && ! empty($status['label']))
                        <x-filament::badge :color="$status['color']" size="sm">
                            {{ $status['label'] }}
                        </x-filament::badge>
                    @endif
                @endforeach
            </div>
        @endif

        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-m-information-circle" class="h-4 w-4 text-amber-500 shrink-0" />
            <span>{{ __('attendance::filament/pages/overview.grid.pending-note') }}</span>
        </div>
    </div>

    {{-- مفتاح عرض الجدول: ملخص الموظفين أو السجل اليومي (مخفية في وضع السنة: ملخص فقط) --}}
    @if ($this->rangeMode !== 'year')
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <x-filament::button
                wire:click="showRegisterView('totals')"
                :color="$this->registerView === 'totals' ? 'primary' : 'gray'"
                :outlined="$this->registerView !== 'totals'"
                icon="heroicon-m-squares-2x2"
            >
                {{ __('attendance::filament/pages/overview.table.view.totals') }}
            </x-filament::button>

            <x-filament::button
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
        يوزع المساحة الفارغة على كل الأعمدة فيتمدد الرقم بعيدًا عن ترويسته --}}
    <style>
        .attendance-register-table .fi-ta-table {
            table-layout: fixed;
        }
    </style>
    <div class="attendance-register-table">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
