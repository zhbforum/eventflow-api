<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\ManagementApiGuard;
use App\Security\OidcUser;
use App\Service\DeliveryReplayService;
use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class DeliveryReplayController
{
    #[Route(
        '/api/v1/deliveries/{id}/replay',
        name: 'api_deliveries_replay',
        methods: ['POST'],
        format: 'json',
    )]
    public function __invoke(
        string $id,
        Request $request,
        ManagementApiGuard $guard,
        DeliveryReplayService $replayService,
        Security $security,
    ): JsonResponse {
        $denied = $guard->check($request);

        if ($denied !== null) {
            return $denied;
        }

        if (!Uuid::isValid($id)) {
            return new JsonResponse(
                ['error' => 'Webhook delivery not found'],
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store'],
            );
        }

        $requestedBy = 'management-token';

        if ($request->headers->get('Authorization', '') === '') {
            $user = $security->getUser();

            if (!$user instanceof OidcUser) {
                return new JsonResponse(
                    ['error' => 'Forbidden'],
                    Response::HTTP_FORBIDDEN,
                    ['Cache-Control' => 'no-store'],
                );
            }

            $identifier = $user->getUserIdentifier();

            $requestedBy = strlen($identifier) <= 250
                ? 'oidc:' . $identifier
                : 'oidc-sha256:' . hash('sha256', $identifier);
        }

        try {
            $replay = $replayService->requestReplay(
                $id,
                $requestedBy,
            );
        } catch (LogicException) {
            return new JsonResponse(
                ['error' => 'Delivery is not eligible for replay'],
                Response::HTTP_CONFLICT,
                ['Cache-Control' => 'no-store'],
            );
        }

        if ($replay === null) {
            return new JsonResponse(
                ['error' => 'Webhook delivery not found'],
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new JsonResponse(
            [
                'replayId' => $replay->getId(),
                'deliveryId' => $id,
                'replayGeneration' =>
                    $replay->getReplayGeneration(),
                'status' => 'pending',
                'requestedAt' => $replay
                    ->getRequestedAt()
                    ->format(DATE_ATOM),
            ],
            Response::HTTP_ACCEPTED,
            [
                'Location' => '/api/v1/deliveries/' . $id,
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
