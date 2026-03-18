<?php

declare(strict_types=1);

namespace Devexa\AiRecommendation\Console\Command;

use Devexa\AiRecommendation\Cron\PreFetch;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command for manual pre-fetching of AI recommendations.
 *
 * Usage:
 *   bin/magento devexa:recommendations:prefetch
 *   bin/magento devexa:recommendations:prefetch --type=frequently_bought
 *   bin/magento devexa:recommendations:prefetch --limit=100
 */
class PreFetchCommand extends Command
{
    private const OPTION_TYPE = 'type';
    private const OPTION_LIMIT = 'limit';

    public function __construct(
        private readonly PreFetch $preFetch
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('devexa:recommendations:prefetch')
            ->setDescription('Pre-fetch AI recommendations for popular products into the local cache')
            ->addOption(
                self::OPTION_TYPE,
                't',
                InputOption::VALUE_OPTIONAL,
                'Recommendation type to pre-fetch (frequently_bought, upsell, crosssell, similar). Omit for all types.',
                null
            )
            ->addOption(
                self::OPTION_LIMIT,
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of products to pre-fetch recommendations for',
                '200'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getOption(self::OPTION_TYPE);
        $limit = (int) $input->getOption(self::OPTION_LIMIT);

        $validTypes = ['frequently_bought', 'upsell', 'crosssell', 'similar'];

        if ($type !== null && !in_array($type, $validTypes, true)) {
            $output->writeln(sprintf(
                '<error>Invalid type "%s". Valid types: %s</error>',
                $type,
                implode(', ', $validTypes)
            ));
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Pre-fetching recommendations (limit=%d, type=%s)...</info>',
            $limit,
            $type ?? 'all'
        ));

        try {
            $this->preFetch->execute($limit, $type);
            $output->writeln('<info>Pre-fetch completed successfully.</info>');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Pre-fetch failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
