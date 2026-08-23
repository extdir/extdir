<?php

declare(strict_types=1);

namespace App\Ui\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Writes /.well-known/security.txt as a real file.
 *
 * It cannot be a route. Apache on this host refuses any path beginning with a dot that
 * has to be rewritten, so the controller version answered 403 in production while
 * working locally. Measured rather than assumed: /.foo/bar.txt and /.deploying both
 * answer 403, /.well-known/acme-challenge/ is intercepted by the host, and a real file
 * placed at /.well-known/probe.txt served 200. A file works; a rewrite does not.
 *
 * Which brings back the problem the route existed to avoid. RFC 9116 requires Expires
 * and treats a past date as invalid, so a hand-written file rots quietly: it keeps
 * serving, and only a validator ever notices it stopped counting. Generating it on every
 * deploy and again every night means the date cannot go stale while anything else about
 * the site is still alive.
 */
#[AsCommand(
    name: 'app:ui:security-txt',
    description: 'Write .well-known/security.txt with a fresh RFC 9116 expiry',
)]
final class WriteSecurityTxtCommand extends Command
{
    /**
     * Deliberately short.
     *
     * A year would mean a file written today is still nominally valid long after
     * anybody stopped looking at this project. Six months is the interval at which a
     * silent failure to regenerate becomes visible while it still matters.
     */
    private const string VALID_FOR = '+6 months';

    private const string CONTACT = 'legal@extdir.com';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $contact = self::CONTACT;
        $expires = (new \DateTimeImmutable())->modify(self::VALID_FOR);
        $canonical = $this->urls->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $policy = $this->urls->generate('takedown', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $body = <<<SECURITY
            Contact: mailto:{$contact}
            Expires: {$expires->format(\DateTimeInterface::ATOM)}
            Preferred-Languages: en, de
            Canonical: {$canonical}.well-known/security.txt
            Policy: {$policy}

            # extdir indexes public metadata about other people's software and hosts
            # none of it. A vulnerability in an indexed extension belongs to its
            # maintainer, whose repository is linked on every entry. This address is
            # for the directory itself.

            SECURITY;

        $directory = $this->projectDir.'/.well-known';

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $io->error('Could not create '.$directory);

            return Command::FAILURE;
        }

        if (false === file_put_contents($directory.'/security.txt', $body)) {
            $io->error('Could not write security.txt');

            return Command::FAILURE;
        }

        $io->success(\sprintf('security.txt written, valid until %s.', $expires->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
