<?php

declare(strict_types=1);

namespace App\Submission\ProofFile;

/**
 * Turns a hostname into the addresses it currently answers with.
 *
 * A seam rather than a call to dns_get_record inside SafeFetcher, for one reason:
 * the address filter is the part of this application most worth testing exhaustively,
 * and a test that has to reach a real resolver to check that 169.254.169.254 is
 * refused is a test that fails on a train. The interface lets the refusal rules be
 * exercised against every dangerous range without a network.
 */
interface HostResolver
{
    /**
     * @return list<string> addresses, or an empty list if the name does not resolve
     */
    public function resolve(string $host): array;
}
