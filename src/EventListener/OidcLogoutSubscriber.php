<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class OidcLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%env(OIDC_ISSUER)%')]
        private string $issuer,

        #[Autowire('%env(OIDC_CLIENT_ID)%')]
        private string $clientId,

        #[Autowire('%env(OIDC_POST_LOGOUT_REDIRECT_URI)%')]
        private string $postLogoutRedirectUri,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        if (
            $event->getRequest()->attributes->get('_route')
            !== 'api_auth_logout'
        ) {
            return;
        }

        $url = rtrim($this->issuer, '/')
            . '/protocol/openid-connect/logout?'
            . http_build_query(
                [
                    'client_id' => $this->clientId,
                    'post_logout_redirect_uri' =>
                    $this->postLogoutRedirectUri,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986,
            );

        $response = new RedirectResponse(
            $url,
            Response::HTTP_SEE_OTHER,
        );

        $response->headers->set(
            'Cache-Control',
            'no-store',
        );

        $event->setResponse($response);
    }
}
