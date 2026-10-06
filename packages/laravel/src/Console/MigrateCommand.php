<?php

declare(strict_types=1);

namespace Accord\Laravel\Console;

use Accord\Laravel\Accord;
use Illuminate\Console\Command;

final class MigrateCommand extends Command
{
    protected $signature = 'accord:migrate';

    protected $description = 'Apply the Accord database migrations (same ledger as the TypeScript server)';

    public function handle(Accord $accord): int
    {
        $applied = $accord->migrate();
        $this->info($applied === [] ? 'accord: database is up to date' : 'accord: applied ' . implode(', ', $applied));

        return self::SUCCESS;
    }
}
