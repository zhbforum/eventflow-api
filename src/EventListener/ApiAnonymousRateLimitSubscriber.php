<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\OidcUser;
use App\Service\PostgreSqlRateLimiter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiAnonymousRateLimitSubscriber implements EventSubscriberInterface
{
    private const WINDOW_SECONDS = 60;
    private const GENERAL_LIMIT = 300;
    private const LOGIN_LIMIT = 20;

    private const EXCLUDED_ROUTES = [
        'api_auth_callback' => true,
        'api_auth_logout' => true,
        'api_auth_logged_out' => true,
    ];

    public function __construct(
        private PostgreSqlRateLimiter $limiter,
        private Security $security,
        #[Autowire('%env(bool:API_RATE_LIMIT_ENABLED)%')]
        private bool $enabled,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (
            !$this->enabled
            || !$event->isMainRequest()
            || $event->hasResponse()
        ) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return;
        }

        $route = $request->attributes->get('_route');

        if (
            !is_string($route)
            || isset(self::EXCLUDED_ROUTES[$route])
        ) {
            return;
        }

        $isLogin = $route === 'api_auth_login';

        $authorization = $request->headers->get(
            'Authorization',
            '',
        );

        if (
            !$isLogin
            && $authorization === ''
            && $this->security->getUser() instanceof OidcUser
        ) {
            return;
        }

        $category = $isLogin ? 'login' : 'general';
        $limit = $isLogin
            ? self::LOGIN_LIMIT
            : self::GENERAL_LIMIT;

        $clientIp = $request->getClientIp() ?? 'unknown';

        $bucketKey = hash(
            'sha256',
            'eventflow:v1:anon:' . $category . ':' . $clientIp,
        );

        $decision = $this->limiter->consume(
            $bucketKey,
            $limit,
            self::WINDOW_SECONDS,
        );

        if ($decision->allowed) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'Rate limit exceeded'],
            Response::HTTP_TOO_MANY_REQUESTS,
            [
                'Cache-Control' => 'no-store',
                'Retry-After' => (string) $decision->retryAfter,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ],
        ));
    }
}
