<?php

declare(strict_types=1);

namespace App\Compatibility\Command;

use App\Compatibility\CompatibilityResolver;
use App\Compatibility\ConstraintParser;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Ingestion\Packagist\PackagistClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the compatibility matrix we would derive for a package, straight from
 * Packagist and without touching the database.
 *
 * This exists for the verification step the build plan insists on: unit tests
 * confirm the parser does what we designed, but only comparing real packages
 * against their own READMEs confirms we designed the right thing. Constraint
 * parsing that is subtly wrong produces a plausible-looking matrix and no errors
 * at all, so it has to be checked by eye against reality, repeatedly, and this is
 * the tool for doing that.
 */
#[AsCommand(
    name: 'app:compat:explain',
    description: 'Show the compatibility matrix derived for a package, for hand-checking',
)]
final class ExplainCompatibilityCommand extends Command
{
    public function __construct(
        private readonly PackagistClient $packagist,
        private readonly ConstraintParser $parser,
        private readonly CompatibilityResolver $resolver,
        private readonly ShopwareVersionRepository $versions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::REQUIRED, 'Composer package name, e.g. frosh/tools')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many releases to show, newest first', '12');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $package = (string) $input->getArgument('package');
        $limit = max(1, (int) $input->getOption('limit'));

        $shopwareVersions = $this->versions->findShownInMatrix();
        if ([] === $shopwareVersions) {
            $io->error('No Shopware versions known. Run app:shopware:sync-versions first.');

            return Command::FAILURE;
        }

        $releases = $this->packagist->fetchVersions($package);
        if ([] === $releases) {
            $io->error(\sprintf('Packagist returned no versions for "%s".', $package));

            return Command::FAILURE;
        }

        $io->title($package);

        $header = ['Version', 'Declared', 'Source', 'Tier'];
        foreach ($shopwareVersions as $version) {
            $header[] = $version->getMajorMinor();
        }

        $rows = [];
        foreach (\array_slice($releases, 0, $limit) as $composerJson) {
            $parsed = $this->parser->parse($composerJson);
            $matrix = $this->resolver->resolve($parsed, $shopwareVersions);

            $row = [
                \is_string($composerJson['version'] ?? null) ? $composerJson['version'] : '?',
                $parsed->raw ?? '<comment>none</comment>',
                str_replace('shopware/', '', $parsed->source->value),
                $parsed->tier->value,
            ];

            foreach ($shopwareVersions as $version) {
                $row[] = ($matrix[$version->getMajorMinor()] ?? false) ? '<info>yes</info>' : '';
            }

            $rows[] = $row;
        }

        $io->table($header, $rows);

        $io->writeln(\sprintf(
            ' <comment>%d releases total; showing %d.</comment>',
            \count($releases),
            min($limit, \count($releases)),
        ));
        $io->writeln(' <comment>Compare this against the project README before trusting it.</comment>');
        $io->newLine();

        return Command::SUCCESS;
    }
}
