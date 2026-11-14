<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\ManagementApiGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class ManagementApiAccessListener
{
    private const PROTECTED_ROUTES = [
        'api_events_show',
        'api_webhooks_create',
        'api_webhooks_show',
    ];

    public function __construct(
        private ManagementApiGuard $guard,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (!in_array($route, self::PROTECTED_ROUTES, true)) {
            return;
        }

        $denied = $this->guard->check($request);

        if ($denied !== null) {
            $event->setResponse($denied);
        }
    }
}
