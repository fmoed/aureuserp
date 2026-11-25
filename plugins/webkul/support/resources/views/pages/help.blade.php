<x-filament-panels::page>
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-4">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('support::filament/pages/help.resources.group') }}
            </span>

            {{ $this->resourcesInfolist }}
        </div>
    </div>
</x-filament-panels::page>
