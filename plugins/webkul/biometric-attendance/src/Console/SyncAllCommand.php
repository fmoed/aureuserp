<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Webkul\BiometricAttendance\Services\SyncPipeline;

class SyncAllCommand extends Command
{
    protected $signature = 'biometric:sync-all
        {--chain : Also push matched punches and pair attendance rows}
        {--company=* : Limit the run to specific company IDs (default: every company)}';

    protected $description = 'Sync attendance logs from all active devices (one offline device never blocks the rest)';

    public function handle(SyncPipeline $pipeline): int
    {
        $lock = Cache::lock('biometric:sync-all', 600);

        if (! $lock->get()) {
            $this->warn('Another biometric sync is already running. Skipping.');

            return self::SUCCESS;
        }

        try {
            $companyIds = collect((array) $this->option('company'))
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $summary = $pipeline->syncAll(
                (bool) $this->option('chain'),
                $companyIds === [] ? null : $companyIds,
            );
        } finally {
            $lock->release();
        }

        if ($summary['total'] === 0) {
            $this->warn('No active devices found.');

            return self::SUCCESS;
        }

        foreach ($summary['devices'] as $device) {
            if ($device['error'] !== null) {
                $this->error("[{$device['name']}] {$device['error']}");

                continue;
            }

            $result = $device['result'];

            $this->info("[{$device['name']}] stored: {$result['stored']}, already present: {$result['skipped']}, backfilled: {$result['backfilled']}.");
        }

        foreach ($summary['push_errors'] as $error) {
            $this->error($error);
        }

        $line = "Synced {$summary['succeeded']}/{$summary['total']} devices — stored: {$summary['stored']}, already present: {$summary['skipped']}, backfilled: {$summary['backfilled']}, failed: {$summary['failed']}.";

        if ($this->option('chain')) {
            $line .= " Pushed: {$summary['pushed']}, push failed: {$summary['push_failed']}.";
        }

        $this->info($line);

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
