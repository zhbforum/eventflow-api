<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

final class ManagementApiRouteCoverageTest extends WebTestCase
{
    private const PUBLIC_AUTH_ROUTES = [
        'api_auth_callback' => true,
        'api_auth_csrf' => true,
        'api_auth_login' => true,
        'api_auth_logout' => true,
        'api_auth_logged_out' => true,
        'api_auth_me' => true,
    ];

    public function testEveryManagementRouteRejectsAnonymousRequests(): void
    {
        $client = static::createClient();

        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);

        $checked = 0;

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (!str_starts_with($path, '/api/v1/')) {
                continue;
            }

            if (isset(self::PUBLIC_AUTH_ROUTES[$name])) {
                continue;
            }

            $parameters = [];

            if (str_contains($path, '{id}')) {
                $parameters['id'] =
                    '00000000-0000-7000-8000-000000000000';
            }

            if (str_contains($path, '{memberId}')) {
                $parameters['memberId'] =
                    '00000000-0000-7000-8000-000000000000';
            }

            $url = $router->generate($name, $parameters);

            foreach ($route->getMethods() as $method) {
                $client->request($method, $url);

                self::assertSame(
                    Response::HTTP_FORBIDDEN,
                    $client->getResponse()->getStatusCode(),
                    sprintf(
                        'Unprotected Management API route: %s %s',
                        $method,
                        $url,
                    ),
                );

                ++$checked;
            }
        }

        self::assertGreaterThanOrEqual(9, $checked);
    }
}
