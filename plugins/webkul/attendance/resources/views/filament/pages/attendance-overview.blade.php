<x-filament-panels::page>
    {{ $this->filterForm }}

    <div class="flex gap-2">
        <button
            type="button"
            wire:click="$set('activeTab', 'daily')"
            @class([
                'fi-btn rounded-lg px-3 py-2 text-sm font-medium',
                'fi-btn-primary' => $activeTab === 'daily',
            ])
        >
            {{ __('attendance::filament/pages/overview.tabs.daily') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'monthly')"
            @class([
                'fi-btn rounded-lg px-3 py-2 text-sm font-medium',
                'fi-btn-primary' => $activeTab === 'monthly',
            ])
        >
            {{ __('attendance::filament/pages/overview.tabs.monthly') }}
        </button>
    </div>

    @if ($activeTab === 'daily')
        <div class="fi-table overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('attendance::filament/pages/overview.table.columns.date') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('attendance::filament/pages/overview.table.columns.employee') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('attendance::filament/pages/overview.table.columns.status') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('attendance::filament/pages/overview.table.columns.check-in') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('attendance::filament/pages/overview.table.columns.check-out') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('attendance::filament/pages/overview.table.columns.minutes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        @php($badge = $this->statusBadge($entry))
                        <tr>
                            <td class="px-4 py-2">{{ $entry['date'] }}</td>
                            <td class="px-4 py-2">{{ $entry['name'] }}</td>
                            <td class="px-4 py-2"><span style="display:inline-block;width:.5rem;height:.5rem;border-radius:9999px;background:{{ $badge['color'] }}"></span> {{ $badge['label'] }}</td>
                            <td class="px-4 py-2">{{ $entry['check_in'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $entry['check_out'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $entry['minutes'] === null ? '—' : intdiv($entry['minutes'], 60).'h '.($entry['minutes'] % 60).'m' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center">{{ __('attendance::filament/pages/overview.table.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('attendance::filament/pages/overview.grid.legend') }}</p>

        <div class="fi-table overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('attendance::filament/pages/overview.table.columns.employee') }}</th>
                        @foreach ($gridDays as $day)
                            <th class="px-2 py-2 text-center">{{ \Carbon\Carbon::parse($day)->format('D j') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid as $row)
                        <tr>
                            <td class="px-4 py-2">{{ $row['name'] }}</td>
                            @foreach ($gridDays as $day)
                                <td class="px-2 py-2 text-center"><span>{{ $row['cells'][$day] ?? '' }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
