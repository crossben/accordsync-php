<?php

declare(strict_types=1);

namespace Accord\Symfony\Command;

use Accord\Symfony\Accord;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'accord:compact', description: 'Run Accord log compaction once')]
final class CompactCommand extends Command
{
    public function __construct(private readonly Accord $accord)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->accord->compact(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
