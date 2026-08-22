<?php

declare(strict_types=1);

namespace App\Compatibility\Command;

use App\Compatibility\Entity\ShopwareVersion;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Ingestion\Packagist\PackagistClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the Shopware release reference table from shopware/core on Packagist.
 *
 * Deliberately not a hardcoded fixture. This table underpins both halves of the
 * product, the compatibility matrix columns and the maintenance signal that asks
 * "has anyone touched this since the current Shopware shipped?", so it must stay
 * correct on the day 6.8 is released, not on the day someone remembers to update a
 * constant. Deriving it from the same source Composer resolves against also means
 * the dates are the real tag dates rather than someone's recollection of them.
 */
#[AsCommand(
    name: 'app:shopware:sync-versions',
    description: 'Refresh the Shopware version reference table from Packagist',
)]
final class SyncShopwareVersionsCommand extends Command
{
    private const CORE_PACKAGE = 'shopware/core';

    public function __construct(
        private readonly PackagistClient $packagist,
        private readonly ShopwareVersionRepository $versions,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'matrix-columns',
            null,
            InputOption::VALUE_REQUIRED,
            'How many of the newest minors to render as columns in the public matrix',
            '5',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $columns = max(1, (int) $input->getOption('matrix-columns'));

        $releases = $this->collectMinorReleaseDates();

        if ([] === $releases) {
            $io->error('Packagist returned no usable versions for '.self::CORE_PACKAGE.'.');

            return Command::FAILURE;
        }

        $ordered = array_keys($releases);
        usort($ordered, static fn (string $a, string $b): int => version_compare($a, $b));

        $total = \count($ordered);
        $rows = [];

        foreach ($ordered as $index => $majorMinor) {
            [$major, $minor] = array_map(intval(...), explode('.', $majorMinor));
            $upper = \sprintf('%d.%d.0.0', $major, $minor + 1);

            $entity = $this->versions->findByMajorMinor($majorMinor);
            if (null === $entity) {
                $entity = new ShopwareVersion(
                    $majorMinor,
                    \sprintf('%d.%d.0.0', $major, $minor),
                    $upper,
                    $releases[$majorMinor],
                    $index,
                );
                $this->em->persist($entity);
            }

            $isNewest = $index === $total - 1;
            $entity->setCurrent($isNewest);
            $entity->setShownInMatrix($index >= $total - $columns);

            $rows[] = [
                $majorMinor,
                $entity->toConstraintString(),
                $releases[$majorMinor]->format('Y-m-d'),
                $isNewest ? 'current' : '',
                $entity->isShownInMatrix() ? 'yes' : '',
            ];
        }

        $this->em->flush();

        $io->table(['Minor', 'Range', 'Released', 'Status', 'In matrix'], $rows);
        $io->success(\sprintf('Synced %d Shopware minors.', $total));

        return Command::SUCCESS;
    }

    /**
     * Earliest stable release date per major.minor.
     *
     * Pre-releases are excluded on purpose. An RC shipping in April for a version
     * that goes GA in June would drag the maintenance yardstick two months earlier
     * and quietly mark active extensions as lagging.
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function collectMinorReleaseDates(): array
    {
        $earliest = [];

        foreach ($this->packagist->fetchVersions(self::CORE_PACKAGE) as $version) {
            $normalized = $version['version_normalized'] ?? null;
            $time = $version['time'] ?? null;

            if (!\is_string($normalized) || !\is_string($time)) {
                continue;
            }

            if (!preg_match('/^(\d+)\.(\d+)\./', $normalized, $matches)) {
                continue;
            }

            $label = \is_string($version['version'] ?? null) ? $version['version'] : '';
            if (preg_match('/(RC|beta|alpha|dev)/i', $label)) {
                continue;
            }

            $released = new \DateTimeImmutable($time);
            $majorMinor = $matches[1].'.'.$matches[2];

            if (!isset($earliest[$majorMinor]) || $released < $earliest[$majorMinor]) {
                $earliest[$majorMinor] = $released;
            }
        }

        return $earliest;
    }
}
