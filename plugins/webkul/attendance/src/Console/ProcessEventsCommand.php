<?php

namespace Webkul\Attendance\Console;

use Illuminate\Console\Command;
use Webkul\Attendance\Services\EventPairingService;

class ProcessEventsCommand extends Command
{
    public const LOCK_NAME = 'attendance:process-events';

    protected $signature = 'attendance:process-events';

    protected $description = 'Rebuild attendance rows from raw events (idempotent, safe to re-run).';

    public function handle(EventPairingService $pairing): int
    {
        $summary = $pairing->processPending();

        if ($summary['locked']) {
            $this->warn('Another pairing run is in progress. Skipping.');

            return self::SUCCESS;
        }

        $this->info("Processed {$summary['processed']} events into {$summary['rows']} rows ({$summary['open']} open).");

        return self::SUCCESS;
    }
}
