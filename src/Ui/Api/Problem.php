<?php

declare(strict_types=1);

namespace App\Ui\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * An error in the format the rest of the API speaks, per RFC 9457.
 *
 * Its own class because two things produce these and they must agree: the controller,
 * for a slug that does not exist, and ApiProblemListener, for a path that matches no
 * route at all. The second is the one that was wrong: /api/nonsense answered with the
 * HTML error page, so anything reading the API got a document with a nav bar and a
 * search box where it expected a status field.
 */
final class Problem
{
    /**
     * @param array<string, mixed> $extra
     */
    public static function response(int $status, string $detail, string $instance, array $extra = []): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'status' => $status,
                'detail' => $detail,
                'instance' => $instance,
            ] + $extra,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
