<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class OidcSessionCookieSecurityTest extends WebTestCase
{
    public function testSessionCookieConfigurationIsSecure(): void
    {
        self::bootKernel();

        $options = static::getContainer()->getParameter(
            'session.storage.options',
        );

        self::assertIsArray($options);

        self::assertSame(
            'auto',
            $options['cookie_secure'],
        );

        self::assertTrue(
            $options['cookie_httponly'],
        );

        self::assertSame(
            Cookie::SAMESITE_LAX,
            $options['cookie_samesite'],
        );
    }

    public function testHttpsLoginUsesSecureRequestContext(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            'https://localhost/api/v1/auth/login',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FOUND,
        );

        self::assertTrue(
            $client->getRequest()->isSecure(),
            'BrowserKit must simulate an HTTPS request.',
        );

        $cookie = self::sessionCookie($client);

        self::assertTrue($cookie->isHttpOnly());

        self::assertSame(
            Cookie::SAMESITE_LAX,
            $cookie->getSameSite(),
        );
    }

    public function testLocalHttpSessionCookieUsesExpectedPolicy(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            'http://localhost/api/v1/auth/login',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FOUND,
        );

        $cookie = self::sessionCookie($client);

        self::assertFalse($cookie->isSecure());

        self::assertTrue($cookie->isHttpOnly());

        self::assertSame(
            Cookie::SAMESITE_LAX,
            $cookie->getSameSite(),
        );
    }

    private static function sessionCookie(
        KernelBrowser $client,
    ): Cookie {
        $sessionName = $client->getSession()->getName();

        $cookies = array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn(Cookie $cookie): bool =>
            $cookie->getName() === $sessionName,
        ));

        self::assertCount(
            1,
            $cookies,
            'Expected one session cookie.',
        );

        return $cookies[0];
    }
}
