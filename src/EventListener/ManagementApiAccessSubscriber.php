<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\ManagementApiGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ManagementApiAccessSubscriber implements EventSubscriberInterface
{
    private const AUTH_ROUTES = [
        'api_auth_callback' => true,
        'api_auth_csrf' => true,
        'api_auth_login' => true,
        'api_auth_logout' => true,
        'api_auth_logged_out' => true,
        'api_auth_me' => true,
    ];

    public function __construct(
        private ManagementApiGuard $guard,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return;
        }

        $route = $request->attributes->get('_route');

        if (
            is_string($route)
            && isset(self::AUTH_ROUTES[$route])
        ) {
            return;
        }

        $denied = $this->guard->check($request);

        if ($denied !== null) {
            $event->setResponse($denied);
        }
    }
}
