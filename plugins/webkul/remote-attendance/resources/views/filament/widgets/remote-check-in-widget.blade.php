<x-filament-widgets::widget>
    @php
        $employee = $this->currentEmployee();
        $attendance = $this->todayAttendance();
        $checkInTime = $this->todayCheckInFormatted();
        $checkOutTime = $this->todayCheckOutFormatted();
        $accomplishments = $this->todayAccomplishments();
    @endphp

    @if ($employee && app(\Webkul\RemoteAttendance\Services\RemoteCheckInService::class)->isEligible($employee))
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $employee->name }}
                        </span>
                        @if ($employee->workLocation)
                            <x-filament::badge color="gray" size="sm">
                                {{ $employee->workLocation->name }}
                            </x-filament::badge>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($attendance && $checkOutTime)
                            <x-filament::badge color="success">
                                {{ __('remote-attendance::filament/pages/remote-check-in.status.checked-out', ['in' => $checkInTime, 'out' => $checkOutTime]) }}
                            </x-filament::badge>
                        @elseif ($attendance)
                            <x-filament::badge color="info">
                                {{ __('remote-attendance::filament/pages/remote-check-in.status.checked-in', ['in' => $checkInTime]) }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="warning">
                                {{ __('remote-attendance::filament/pages/remote-check-in.status.pending') }}
                            </x-filament::badge>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($this->checkInAction->isVisible())
                        {{ $this->checkInAction }}
                    @endif

                    @if ($this->logWorkAction->isVisible())
                        {{ $this->logWorkAction }}
                    @endif

                    @if ($this->checkOutAction->isVisible())
                        {{ $this->checkOutAction }}
                    @endif
                </div>
            </div>

            @if ($accomplishments->isNotEmpty())
                <div class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('remote-attendance::filament/pages/remote-check-in.table.accomplishments') }}
                    </div>
                    <div class="space-y-1.5 text-sm text-gray-700 dark:text-gray-200">
                        @foreach ($accomplishments as $message)
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5 [&_ul]:list-disc [&_ul]:ps-5">
                                {!! str((string) $message->body)->sanitizeHtml() !!}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-filament::section>

        <x-filament-actions::modals />
    @endif
</x-filament-widgets::widget>
