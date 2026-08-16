<?php

declare(strict_types=1);

namespace App\Taxonomy\Command;

use App\Catalog\Entity\Category;
use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Repository\ExtensionRepository;
use App\Taxonomy\CategoryDefinition;
use App\Taxonomy\CategoryRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the category table and assigns categories to every indexed extension.
 *
 * Runs entirely against stored data, so iterating on the rules costs a few seconds
 * rather than another crawl of Packagist. The uncategorised count it reports is the
 * feedback loop: it is the list of extensions the rules do not yet describe, and
 * driving it down is how the taxonomy improves.
 */
#[AsCommand(
    name: 'app:taxonomy:classify',
    description: 'Sync categories and re-assign them to all extensions',
)]
final class ClassifyExtensionsCommand extends Command
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly CategoryRepository $categories,
        private readonly CategoryRuleEngine $engine,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'show-uncategorised',
            null,
            InputOption::VALUE_NONE,
            'List the extensions no rule matched, to guide the next rule additions',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->syncCategories();
        $categories = $this->categories->findAllKeyed();

        $extensions = $this->extensions->findBy([]);
        $counts = [];
        $uncategorised = [];

        foreach ($extensions as $extension) {
            $keys = $this->engine->categorise(
                $extension->getKeywords(),
                $extension->getLabels(),
                $extension->getDescriptions(),
                $extension->getPackageName(),
            );

            $extension->clearCategories();

            foreach ($keys as $key) {
                if (isset($categories[$key])) {
                    $extension->addCategory($categories[$key]);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }

            if ([] === $keys) {
                $uncategorised[] = $extension->getPackageName();
            }
        }

        $this->em->flush();

        arsort($counts);
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [$key, $categories[$key]->getLabel(), $count];
        }

        $io->table(['Key', 'Category', 'Extensions'], $rows);

        $total = \count($extensions);
        $missed = \count($uncategorised);

        $io->success(\sprintf(
            '%d of %d extensions categorised (%.1f%%); %d matched no rule.',
            $total - $missed,
            $total,
            $total > 0 ? ($total - $missed) / $total * 100 : 0.0,
            $missed,
        ));

        if ($input->getOption('show-uncategorised') && [] !== $uncategorised) {
            $io->section('Matched no rule');
            $io->listing($uncategorised);
        }

        return Command::SUCCESS;
    }

    /**
     * Upserts the category rows from the definition list. Labels and descriptions
     * are refreshed so the definition file stays the single source of truth.
     */
    private function syncCategories(): void
    {
        $existing = $this->categories->findAllKeyed();
        $sort = 0;

        foreach (CategoryDefinition::all() as $key => [$label, $description]) {
            $category = $existing[$key] ?? null;

            if (null === $category) {
                $category = new Category($key, $label, $description, $sort);
                $this->em->persist($category);
            } else {
                $category->setLabel($label);
                $category->setDescription($description);
                $category->setSortOrder($sort);
            }

            ++$sort;
        }

        $this->em->flush();
    }
}
