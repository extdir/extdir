<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

/**
 * The one thing the metadata readers need from GitHub: run a GraphQL query.
 *
 * Extracted for the same reason HostResolver was extracted from SafeFetcher. The
 * concrete client needs an HTTP stack, a database-backed token and a client secret, so
 * anything depending on it directly can only be tested against the live API, and a
 * test that needs a network and a token is a test that fails on a train and is
 * therefore not run.
 *
 * The parsing is where the bugs live. Two tags normalising to one version, a
 * composer.json that is not a plugin, a repository with no usable release: all of that
 * is decided from a response body, and this interface is what lets those decisions be
 * exercised against a canned one.
 */
interface GitHubGraphQl
{
    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>|null the data block, or null if the query failed
     */
    public function graphql(string $query, array $variables = []): ?array;
}
