<?php

declare(strict_types=1);

namespace App\EventListener;

use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiPayloadLimitSubscriber implements EventSubscriberInterface
{
    private const DEFAULT_MAX_BYTES = 65_536;
    private const EVENT_MAX_BYTES = 1_048_576;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (
            !str_starts_with($request->getPathInfo(), '/api/v1/')
            || !in_array(
                $request->getMethod(),
                ['POST', 'PUT', 'PATCH', 'DELETE'],
                true,
            )
        ) {
            return;
        }

        $maxBytes = $request->attributes->get('_route')
            === 'api_events_create'
            ? self::EVENT_MAX_BYTES
            : self::DEFAULT_MAX_BYTES;

        $contentLength = $request->headers->get('Content-Length');

        if (
            $contentLength !== null
            && ctype_digit($contentLength)
            && (int) $contentLength > $maxBytes
        ) {
            $event->setResponse(self::tooLarge());

            return;
        }

        $stream = $request->getContent(true);

        if (!is_resource($stream)) {
            throw new RuntimeException(
                'Unable to read request body stream.',
            );
        }

        $content = stream_get_contents($stream, $maxBytes + 1);

        if ($content === false) {
            throw new RuntimeException(
                'Unable to read request body stream.',
            );
        }

        if (strlen($content) > $maxBytes) {
            $event->setResponse(self::tooLarge());
        }
    }

    private static function tooLarge(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Payload too large'],
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            ['Cache-Control' => 'no-store'],
        );
    }
}
