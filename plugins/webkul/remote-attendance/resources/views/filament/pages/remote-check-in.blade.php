<x-filament-panels::page>
    @if (! \Webkul\RemoteAttendance\Filament\Widgets\RemoteCheckInWidget::canView())
        @php
            $employee = \Webkul\RemoteAttendance\Filament\Widgets\RemoteCheckInWidget::resolveAuthenticatedEmployee();
        @endphp

        <x-filament::section>
            <div class="text-sm text-gray-600 dark:text-gray-400">
                {{ $employee
                    ? __('remote-attendance::filament/pages/remote-check-in.status.not-eligible')
                    : __('remote-attendance::filament/pages/remote-check-in.status.no-employee') }}
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
