<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\GitHub\DeviceFlowAuthenticator;
use App\Ingestion\GitHub\GitHubClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Authorises the crawler against GitHub and verifies the token can do the job.
 *
 * The verification step is the point of the command as much as the authorisation
 * is. The entire enrichment stage rests on an assumption — that a user access token
 * can read public repositories the App is not installed on — and an assumption that
 * load-bearing should be checked against the live API rather than inferred from
 * documentation.
 */
#[AsCommand(
    name: 'app:github:authorize',
    description: 'Authorise the crawler against GitHub via the device flow',
)]
final class AuthorizeGitHubCommand extends Command
{
    /**
     * A well-known public repository that extdir will never own or be installed
     * on. If this reads, the crawl works.
     */
    private const CANARY_REPOSITORY = 'FriendsOfShopware/FroshTools';

    public function __construct(
        private readonly DeviceFlowAuthenticator $authenticator,
        private readonly GitHubClient $github,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Authorise extdir against GitHub');

        try {
            $code = $this->authenticator->requestDeviceCode();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(' Open this page and enter the code below:');
        $io->newLine();
        $io->writeln(\sprintf('   <href=%s>%s</>', $code->verificationUri, $code->verificationUri));
        $io->newLine();
        $io->writeln(\sprintf('   Code: <info>%s</info>', $code->userCode));
        $io->newLine();
        $io->writeln(\sprintf(
            ' <comment>Waiting for authorisation (expires in %d minutes)…</comment>',
            intdiv($code->expiresIn, 60),
        ));

        try {
            $token = $this->authenticator->pollForToken(
                $code,
                static fn (string $note) => $output->isVerbose() ? $io->writeln('   '.$note) : null,
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Authorised.');

        return $this->verify($io, $token->getExpiresAt()?->format('Y-m-d H:i') ?? 'never');
    }

    private function verify(SymfonyStyle $io, string $expiry): int
    {
        $io->section('Verifying the token can do what the crawler needs');

        $login = $this->github->authenticatedLogin();
        $rateLimit = $this->github->rateLimit();
        $canary = $this->github->repository(self::CANARY_REPOSITORY);

        $io->definitionList(
            ['Authorised as' => $login ?? '<error>unknown</error>'],
            ['Token expires' => $expiry],
            ['Rate limit' => null === $rateLimit ? '<error>unknown</error>' : $rateLimit.'/hour'],
            ['Canary repo' => self::CANARY_REPOSITORY],
            ['Canary read' => null === $canary
                ? '<error>FAILED</error>'
                : \sprintf('<info>OK</info> (%d stars)', $canary['stargazers_count'] ?? 0)],
        );

        if (null === $canary) {
            $io->error([
                'Could not read a public repository the app is not installed on.',
                'Enrichment cannot work with this token. The likely cause is that an installation '
                .'token was issued instead of a user token — installation tokens are scoped to the '
                .'repositories the app is installed on.',
            ]);

            return Command::FAILURE;
        }

        if (null !== $rateLimit && $rateLimit < 5000) {
            $io->warning(\sprintf(
                'Rate limit is %d/hour rather than the expected 5,000. The requests are probably '
                .'unauthenticated, which is far too low to enrich the corpus.',
                $rateLimit,
            ));

            return Command::FAILURE;
        }

        $io->success('Token verified: public repositories are readable at the full rate limit.');

        return Command::SUCCESS;
    }
}
