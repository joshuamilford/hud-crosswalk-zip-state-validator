<?php

namespace App\Command;

use App\Entity\ZipCodeState;
use App\Repository\ZipCodeStateRepository;
use App\Service\HudCrosswalkClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hud:import-zip-states',
    description: 'Imports ZIP→state mappings from the HUD USPS Crosswalk API and flags multi-state ZIPs.',
)]
class ImportHudZipStatesCommand extends Command
{
    public function __construct(
        private readonly HudCrosswalkClient $hudClient,
        private readonly ZipCodeStateRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'states',
                's',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of state abbreviations to import (default: all)',
                'ALL',
            )
            ->addOption(
                'quarter',
                null,
                InputOption::VALUE_OPTIONAL,
                'Data quarter label to record (e.g. 2024Q4). Defaults to current quarter.',
            )
            ->addOption(
                'truncate',
                null,
                InputOption::VALUE_NONE,
                'Truncate the table before importing (recommended for full refresh)',
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Doctrine flush batch size',
                '200',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '-1');

        $io = new SymfonyStyle($input, $output);

        $statesOption = strtoupper((string) $input->getOption('states'));
        $states = ($statesOption === 'ALL')
            ? HudCrosswalkClient::ALL_STATE_QUERIES
            : array_filter(array_map('trim', explode(',', $statesOption)));

        $quarter   = $input->getOption('quarter') ?? $this->currentQuarterLabel();
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('HUD USPS Crosswalk Import');
        $io->definitionList(
            ['States'        => implode(', ', $states)],
            ['Quarter'       => $quarter],
            ['Truncate first' => $input->getOption('truncate') ? 'yes' : 'no'],
        );

        if ($input->getOption('truncate')) {
            $io->warning('Truncating zip_code_state table...');
            $this->repository->truncate();
            $io->success('Table truncated.');
        }

        $progress = new ProgressBar($output, count($states));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
        $progress->setMessage('Starting...');
        $progress->start();

        $totalInserted = 0;
        $totalSkipped  = 0;
        $seen          = [];

        foreach ($states as $stateAbbr) {
            $progress->setMessage('Fetching ' . $stateAbbr . '...');
            $progress->advance();

            try {
                $rows = $this->hudClient->fetchZipsForState($stateAbbr);
            } catch (\Throwable $e) {
                $io->error('Failed to fetch state ' . $stateAbbr . ': ' . $e->getMessage());
                continue;
            }

            foreach ($rows as $row) {
                $key = $row['zip'] . '|' . $row['state_abbr'];
                if (isset($seen[$key])) {
                    $totalSkipped++;
                    continue;
                }
                $seen[$key] = true;

                $entity = new ZipCodeState(
                    zipCode:     $row['zip'],
                    stateAbbr:   $row['state_abbr'],
                    stateFips:   $row['state_fips'],
                    resRatio:    $row['res_ratio'],
                    dataQuarter: $quarter,
                );
                $this->em->persist($entity);
                $totalInserted++;

                if ($totalInserted % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();
                }
            }
        }

        $this->em->flush();
        $this->em->clear();

        $progress->finish();
        $output->writeln('');

        // --- Flag multi-state ZIPs directly from the database ---
        // Uses a nested subquery for SQLite compatibility — SQLite does not support
        // UPDATE ... WHERE x IN (SELECT ... FROM the same table) without an extra nesting level.
        $io->section('Flagging multi-state ZIP codes...');

        $conn    = $this->em->getConnection();
        $flagged = $conn->executeStatement("
            UPDATE zip_code_state
            SET is_multi_state = 1
            WHERE zip_code IN (
                SELECT zip_code FROM (
                    SELECT zip_code
                    FROM zip_code_state
                    GROUP BY zip_code
                    HAVING COUNT(DISTINCT state_abbr) > 1
                ) AS multi
            )
        ");

        $io->success(sprintf(
            'Import complete. Inserted: %d | Skipped (duplicate): %d | Multi-state ZIP rows flagged: %d',
            $totalInserted,
            $totalSkipped,
            $flagged,
        ));

        return Command::SUCCESS;
    }

    private function currentQuarterLabel(): string
    {
        $month   = (int) date('n');
        $year    = date('Y');
        $quarter = (int) ceil($month / 3);
        return "{$year}Q{$quarter}";
    }
}
