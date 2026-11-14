<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateWebhookRequest;
use App\Entity\WebhookEndpoint;
use App\Security\ManagementApiGuard;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class WebhookUpdateController
{
    #[Route(
        '/api/v1/webhooks/{id}',
        name: 'api_webhooks_update',
        methods: ['PATCH'],
        format: 'json',
    )]
    public function __invoke(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ManagementApiGuard $guard,
        ValidatorInterface $validator,
    ): JsonResponse {
        $denied = $guard->check($request);

        if ($denied !== null) {
            return $denied;
        }

        $endpoint = $entityManager
            ->getRepository(WebhookEndpoint::class)
            ->find($id);

        if ($endpoint === null) {
            return self::error('Webhook endpoint not found', 404);
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return self::error('Content-Type must be application/json', 415);
        }

        try {
            $data = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return self::error('Malformed JSON body', 400);
        }

        if (
            !is_array($data)
            || $data === []
            || array_diff(
                array_keys($data),
                ['url', 'subscribedEvents', 'isActive'],
            ) !== []
        ) {
            return self::error('Invalid update payload', 400);
        }

        $url = $data['url'] ?? $endpoint->getUrl();

        $subscribedEvents = $data['subscribedEvents']
            ?? $endpoint->getSubscribedEvents();

        $isActive = $data['isActive'] ?? $endpoint->isActive();

        foreach (['url', 'subscribedEvents', 'isActive'] as $field) {
            if (
                array_key_exists($field, $data)
                && $data[$field] === null
            ) {
                return self::error(
                    sprintf('%s cannot be null', $field),
                    422,
                );
            }
        }

        if (
            !is_string($url)
            || !is_array($subscribedEvents)
            || !array_is_list($subscribedEvents)
            || !is_bool($isActive)
        ) {
            return self::error('Invalid field types', 422);
        }

        $input = new CreateWebhookRequest(
            $url,
            $subscribedEvents,
        );

        $violations = $validator->validate($input);

        if (count($violations) > 0) {
            $errors = [];

            foreach ($violations as $violation) {
                $errors[] = [
                    'propertyPath' => $violation->getPropertyPath(),
                    'message' => $violation->getMessage(),
                ];
            }

            return new JsonResponse(
                ['violations' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (
            parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
        ) {
            return self::error('URL credentials are not allowed', 422);
        }

        $endpoint->update(
            $url,
            $subscribedEvents,
            $isActive,
        );

        $entityManager->flush();

        return new JsonResponse(
            [
                'id' => $endpoint->getId(),
                'url' => $endpoint->getUrl(),
                'subscribedEvents' => $endpoint->getSubscribedEvents(),
                'isActive' => $endpoint->isActive(),
                'createdAt' => $endpoint->getCreatedAt()->format(DATE_ATOM),
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    private static function error(
        string $message,
        int $status,
    ): JsonResponse {
        return new JsonResponse(
            ['error' => $message],
            $status,
            ['Cache-Control' => 'no-store'],
        );
    }
}
