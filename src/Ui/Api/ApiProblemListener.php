<?php

declare(strict_types=1);

namespace App\Ui\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Errors under /api answer as JSON, whatever went wrong.
 *
 * The controller already returned a problem document for a slug it could not find, but
 * that only covers paths that reached the controller. A request for /api/nonsense
 * matches no route, so it went to the HTML error page: a full page, with a nav bar and
 * a search box, served as text/html to something that had asked for an API.
 *
 * A client that mistypes a path is exactly the client that most needs a parsable
 * answer, and it is the one case nobody tests by hand because they type the URL
 * correctly.
 *
 * Errors only. A matched route returns its own response and never reaches this.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final class ApiProblemListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        // The message is only safe to repeat when the framework raised it deliberately.
        // Anything else can carry a file path, a query or a credential, and an API is
        // the last place to leak one.
        $detail = $exception instanceof HttpExceptionInterface && '' !== $exception->getMessage()
            ? $exception->getMessage()
            : 'The request could not be completed.';

        if (Response::HTTP_NOT_FOUND === $status) {
            $detail = 'No such endpoint. GET /api lists what this API answers.';
        }

        $event->setResponse(Problem::response($status, $detail, $request->getPathInfo()));
    }
}
