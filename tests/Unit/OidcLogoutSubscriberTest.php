<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventListener\OidcLogoutSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class OidcLogoutSubscriberTest extends TestCase
{
    public function testMissingConfigurationPreservesLogoutResponse(): void
    {
        $subscriber = new OidcLogoutSubscriber(
            null,
            null,
            null,
        );

        $event = self::createLogoutEvent('api_auth_logout');
        $originalResponse = new Response('Logged out');

        $event->setResponse($originalResponse);

        $subscriber->onLogout($event);

        self::assertSame(
            $originalResponse,
            $event->getResponse(),
        );
    }

    public function testIncompleteConfigurationDoesNotRedirect(): void
    {
        $subscriber = new OidcLogoutSubscriber(
            'https://auth.example.test/realms/eventflow',
            'eventflow-bff',
            null,
        );

        $event = self::createLogoutEvent('api_auth_logout');
        $originalResponse = new Response('Logged out');

        $event->setResponse($originalResponse);

        $subscriber->onLogout($event);

        self::assertSame(
            $originalResponse,
            $event->getResponse(),
        );
    }

    public function testConfiguredLogoutRedirectsToIdentityProvider(): void
    {
        $subscriber = new OidcLogoutSubscriber(
            'https://auth.example.test/realms/eventflow',
            'eventflow-bff',
            'https://api.example.test/api/v1/auth/logged-out',
        );

        $event = self::createLogoutEvent('api_auth_logout');

        $subscriber->onLogout($event);

        $response = $event->getResponse();

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );

        self::assertSame(
            Response::HTTP_SEE_OTHER,
            $response->getStatusCode(),
        );

        self::assertTrue(
            $response->headers->hasCacheControlDirective('no-store'),
        );

        self::assertSame(
            'https://auth.example.test/realms/eventflow'
                . '/protocol/openid-connect/logout'
                . '?client_id=eventflow-bff'
                . '&post_logout_redirect_uri='
                . 'https%3A%2F%2Fapi.example.test%2Fapi%2Fv1%2Fauth%2Flogged-out',
            $response->headers->get('Location'),
        );
    }

    public function testUnrelatedRouteDoesNotRedirect(): void
    {
        $subscriber = new OidcLogoutSubscriber(
            'https://auth.example.test/realms/eventflow',
            'eventflow-bff',
            'https://api.example.test/api/v1/auth/logged-out',
        );

        $event = self::createLogoutEvent('api_health');

        $subscriber->onLogout($event);

        self::assertNull($event->getResponse());
    }

    private static function createLogoutEvent(
        string $route,
    ): LogoutEvent {
        $request = Request::create(
            '/api/v1/auth/logout',
            'POST',
        );

        $request->attributes->set('_route', $route);

        return new LogoutEvent($request, null);
    }
}
