<?php

declare(strict_types=1);

namespace Accord\Laravel\Console;

use Accord\Laravel\Accord;
use Illuminate\Console\Command;

final class CompactCommand extends Command
{
    protected $signature = 'accord:compact';

    protected $description = 'Run Accord log compaction once';

    public function handle(Accord $accord): int
    {
        $this->line(json_encode($accord->compact(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
