<?php

declare(strict_types=1);

namespace App\Signals;

use App\Catalog\Entity\Extension;
use App\Signals\Entity\RepositorySnapshot;
use App\Signals\Forge\ForgeClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Maintenance signals for the extensions GitHub cannot answer for.
 *
 * Forty-two of 423 extensions live on Bitbucket, GitLab, Gitea or a self-hosted
 * instance, and every one of them showed a dash in every signal column. That is half
 * the stated purpose of the directory missing for the maintainers who are already
 * least visible, the same ones who cannot use API ownership verification either.
 *
 * The fields written are the ones RepositoryEnricher already writes for GitHub, so
 * MaintenanceStatus scoring and the ranking formula need no knowledge of where a
 * repository is hosted.
 *
 * Every fetch goes through SafeFetcher. These URLs come from Packagist metadata,
 * which anyone can publish, and a self-hosted GitLab is by definition an arbitrary
 * host, the same untrusted input that made the proof-file check an SSRF surface.
 * The crawler running on a schedule rather than on demand makes it less attractive
 * to an attacker, not less of a hole.
 */
final readonly class ForgeEnricher
{
    /**
     * @param iterable<ForgeClient> $clients
     */
    public function __construct(
        #[AutowireIterator('app.forge_client')]
        private iterable $clients,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return array{enriched: int, unreachable: int, unsupported: int}
     */
    public function enrich(array $extensions): array
    {
        $enriched = 0;
        $unreachable = 0;
        $unsupported = 0;

        foreach ($extensions as $extension) {
            $client = $this->clientFor($extension);

            if (null === $client) {
                ++$unsupported;
                continue;
            }

            $signals = $client->fetch($extension);

            if (null === $signals) {
                // Private, deleted, or an instance that is down. All ordinary for a
                // corpus assembled from a decade of Packagist metadata, and the row
                // keeps saying "unknown", which is true.
                ++$unreachable;
                $this->logger->info('Forge signals unavailable', [
                    'package' => $extension->getPackageName(),
                    'repository' => $extension->getRepositoryUrl(),
                ]);
                continue;
            }

            $snapshot = new RepositorySnapshot($extension);
            $snapshot
                ->setCounts($signals->stars ?? 0, $signals->forks ?? 0, 0, 0)
                ->setLastCommitAt($signals->lastActivityAt)
                ->setDefaultBranch($signals->defaultBranch)
                ->setArchived($signals->archived);

            $this->em->persist($snapshot);

            $extension->setLastCommitAt($signals->lastActivityAt);
            $extension->setDefaultBranch($signals->defaultBranch);

            // Only where the forge actually publishes the number. Bitbucket counts
            // watchers rather than stars, so leaving the existing value alone beats
            // writing a different measurement into the same field.
            if (null !== $signals->stars) {
                $extension->setPopularity($signals->stars, $signals->forks ?? 0);
            }

            ++$enriched;
        }

        $this->em->flush();

        return ['enriched' => $enriched, 'unreachable' => $unreachable, 'unsupported' => $unsupported];
    }

    private function clientFor(Extension $extension): ?ForgeClient
    {
        foreach ($this->clients as $client) {
            if ($client->supports($extension)) {
                return $client;
            }
        }

        return null;
    }
}
