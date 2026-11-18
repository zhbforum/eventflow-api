<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Organization;
use App\Security\OidcUser;
use App\Service\PostgreSqlRateLimiter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    private const WINDOW_SECONDS = 60;

    private const READ_LIMIT = 120;
    private const WRITE_LIMIT = 30;
    private const EVENT_LIMIT = 60;

    private const EXCLUDED_ROUTES = [
        'api_auth_login' => true,
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
            KernelEvents::REQUEST => [
                ['onOidcRequest', 4],
                ['onLegacyRequest', -10],
            ],
        ];
    }

    public function onOidcRequest(RequestEvent $event): void
    {
        if (!$this->shouldProcess($event)) {
            return;
        }

        $request = $event->getRequest();

        if ($request->headers->get('Authorization', '') !== '') {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof OidcUser) {
            return;
        }

        $this->applyLimit(
            $event,
            'oidc:' . $user->getUserIdentifier(),
        );
    }

    public function onLegacyRequest(RequestEvent $event): void
    {
        if (!$this->shouldProcess($event)) {
            return;
        }

        $request = $event->getRequest();

        if (
            $request->headers->get('Authorization', '') === ''
            || $request->attributes->get(
                'eventflow.organization_id',
            ) !== Organization::LEGACY_ID
        ) {
            return;
        }

        $this->applyLimit(
            $event,
            'legacy-management-token',
        );
    }

    private function shouldProcess(RequestEvent $event): bool
    {
        if (
            !$this->enabled
            || !$event->isMainRequest()
            || $event->hasResponse()
        ) {
            return false;
        }

        $request = $event->getRequest();

        if (!str_starts_with(
            $request->getPathInfo(),
            '/api/v1/',
        )) {
            return false;
        }

        $route = $request->attributes->get('_route');

        return is_string($route)
            && !isset(self::EXCLUDED_ROUTES[$route]);
    }

    private function applyLimit(
        RequestEvent $event,
        string $identity,
    ): void {
        $request = $event->getRequest();

        [$category, $limit] = $this->resolveLimit($request);

        $bucketKey = hash(
            'sha256',
            'eventflow:v1:' . $category . ':' . $identity,
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

    /**
     * @return array{string, int}
     */
    private function resolveLimit(Request $request): array
    {
        if (
            $request->attributes->get('_route')
            === 'api_events_create'
        ) {
            return ['events', self::EVENT_LIMIT];
        }

        if ($request->isMethod('GET')) {
            return ['read', self::READ_LIMIT];
        }

        return ['write', self::WRITE_LIMIT];
    }
}
