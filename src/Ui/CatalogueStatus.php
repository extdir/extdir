<?php

declare(strict_types=1);

namespace App\Ui;

use Doctrine\DBAL\Connection;

/**
 * How much is indexed and how recently, for display in the masthead.
 *
 * A directory asks people to trust data about software it did not write. The first
 * question a sceptical reader has is not "how many" but "how old", and answering it
 * unprompted — in the chrome, on every page that shows catalogue data — is a larger
 * part of the trust argument than any amount of visual polish.
 *
 * Deliberately not a Twig global. A global would be evaluated on the 404 page too,
 * and a 404 is the one response that must not touch the database: they are generated
 * constantly by crawlers, and an error page that runs a query turns every bad URL
 * into load. Controllers that already query pass this in; the template renders
 * nothing when they do not.
 *
 * Two cheap aggregates rather than the ORM, because this runs on every catalogue
 * request and hydrating entities to count them would be absurd.
 */
final readonly class CatalogueStatus
{
    /**
     * Beyond this the data is old enough that a reader should be told, rather than
     * shown a number that implies more currency than it has. Two days is the same
     * threshold the health endpoint alerts on, so the badge and the monitor cannot
     * disagree about what "stale" means.
     */
    private const int STALE_AFTER_HOURS = 48;

    public function __construct(private Connection $connection)
    {
    }

    public function total(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM extension WHERE index_status <> 'delisted'",
        );
    }

    public function crawledAt(): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT MAX(last_crawled_at) FROM extension');

        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    public function isStale(): bool
    {
        $crawledAt = $this->crawledAt();

        if (null === $crawledAt) {
            return true;
        }

        return $crawledAt < new \DateTimeImmutable(\sprintf('-%d hours', self::STALE_AFTER_HOURS));
    }

    /**
     * @return array{total: int, crawledAt: ?\DateTimeImmutable, stale: bool}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'crawledAt' => $this->crawledAt(),
            'stale' => $this->isStale(),
        ];
    }
}
