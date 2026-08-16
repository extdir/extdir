<?php

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Repository\ExtensionRepository;
use App\Catalog\SearchTextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the denormalised search text for every extension.
 *
 * Ingestion writes this column as it goes, so this exists for the case where the
 * *builder* changes rather than the data — adding a field to the indexed text, or
 * fixing how locales are folded in. Recomputing from stored metadata takes seconds;
 * re-crawling Packagist to achieve the same thing would be several hundred HTTP
 * requests for information already sitting in the database.
 */
#[AsCommand(
    name: 'app:catalog:rebuild-search-text',
    description: 'Recompute the searchable text for all extensions from stored metadata',
)]
final class RebuildSearchTextCommand extends Command
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly SearchTextBuilder $builder,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $extensions = $this->extensions->findBy([]);
        $changed = 0;

        foreach ($extensions as $extension) {
            $text = $this->builder->build($extension);

            if ($text !== $extension->getSearchText()) {
                $extension->setSearchText($text);
                ++$changed;
            }
        }

        $this->em->flush();

        $io->success(\sprintf('%d of %d extensions updated.', $changed, \count($extensions)));

        return Command::SUCCESS;
    }
}
