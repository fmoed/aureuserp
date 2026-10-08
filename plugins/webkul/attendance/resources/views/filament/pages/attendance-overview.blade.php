<x-filament-panels::page>
    <x-filament::section :heading="__('attendance::filament/pages/overview.form.heading')">
        {{ $this->filterForm }}

        <x-filament::button wire:click="applyFilters">
            {{ __('attendance::filament/pages/overview.form.apply') }}
        </x-filament::button>
    </x-filament::section>

    <x-filament::tabs>
        <x-filament::tabs.item :active="$activeTab === 'daily'" wire:click="$set('activeTab', 'daily')">
            {{ __('attendance::filament/pages/overview.tabs.daily') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item :active="$activeTab === 'monthly'" wire:click="$set('activeTab', 'monthly')">
            {{ __('attendance::filament/pages/overview.tabs.monthly') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($activeTab === 'daily')
        @php($paginator = $this->getDailyPaginator())

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="fi-ta-table">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell">{{ __('attendance::filament/pages/overview.table.columns.date') }}</th>
                        <th class="fi-ta-header-cell">{{ __('attendance::filament/pages/overview.table.columns.employee') }}</th>
                        <th class="fi-ta-header-cell">{{ __('attendance::filament/pages/overview.table.columns.status') }}</th>
                        <th class="fi-ta-header-cell">{{ __('attendance::filament/pages/overview.table.columns.check-in') }}</th>
                        <th class="fi-ta-header-cell">{{ __('attendance::filament/pages/overview.table.columns.check-out') }}</th>
                        <th class="fi-ta-header-cell">{{ __('attendance::filament/pages/overview.table.columns.minutes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($paginator->items() as $entry)
                        @php($badge = $this->statusBadge($entry))
                        <tr>
                            <td class="fi-ta-cell">{{ $entry['date'] }}</td>
                            <td class="fi-ta-cell">{{ $entry['name'] }}</td>
                            <td class="fi-ta-cell"><x-filament::badge :color="$badge['color']">{{ $badge['label'] }}</x-filament::badge></td>
                            <td class="fi-ta-cell">{{ $entry['check_in'] ?? '—' }}</td>
                            <td class="fi-ta-cell">{{ $entry['check_out'] ?? '—' }}</td>
                            <td class="fi-ta-cell">{{ $entry['minutes'] === null ? '—' : intdiv($entry['minutes'], 60).'h '.($entry['minutes'] % 60).'m' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="fi-ta-cell">
                                <x-filament::empty-state :heading="__('attendance::filament/pages/overview.empty.heading')" :description="__('attendance::filament/pages/overview.table.empty')" icon="heroicon-o-calendar-days" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-filament::pagination :paginator="$paginator" :page-options="[10, 25, 50]" current-page-option-property="dailyPerPage" />
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('attendance::filament/pages/overview.grid.legend') }}</p>

        @php($gridDays = $this->getGridDays())
        @php($grid = $this->getGrid())

        @if ($gridDays === [] || $grid === [])
            <x-filament::empty-state :heading="__('attendance::filament/pages/overview.empty.heading')" :description="__('attendance::filament/pages/overview.table.empty')" icon="heroicon-o-calendar-days" />
        @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="fi-ta-table">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell">{{ __('attendance::filament/pages/overview.table.columns.employee') }}</th>
                        @foreach ($gridDays as $day)
                            <th class="fi-ta-header-cell">{{ \Carbon\Carbon::parse($day)->format('D j') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid as $row)
                        <tr>
                            <td class="fi-ta-cell">{{ $row['name'] }}</td>
                            @foreach ($gridDays as $day)
                                <td class="fi-ta-cell"><span>{{ $row['cells'][$day] ?? '' }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    @endif
</x-filament-panels::page>
