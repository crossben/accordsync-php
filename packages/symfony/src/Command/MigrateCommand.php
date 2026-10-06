<?php

declare(strict_types=1);

namespace Accord\Symfony\Command;

use Accord\Symfony\Accord;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'accord:migrate', description: 'Apply the Accord database migrations (same ledger as the TypeScript server)')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly Accord $accord)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applied = $this->accord->migrate();
        $output->writeln($applied === [] ? 'accord: database is up to date' : 'accord: applied ' . implode(', ', $applied));

        return Command::SUCCESS;
    }
}
