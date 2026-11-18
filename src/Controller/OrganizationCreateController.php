<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class OrganizationCreateController
{
    #[Route(
        '/api/v1/organizations',
        name: 'api_organizations_create',
        methods: ['POST'],
        format: 'json',
    )]
    public function __invoke(
        Request $request,
        Security $security,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $security->getUser();

        if (
            !$user instanceof OidcUser
            || $request->headers->get('Authorization', '') !== ''
            || !in_array(
                'ROLE_EVENTFLOW_ADMIN',
                $user->getRoles(),
                true,
            )
        ) {
            return self::error(
                'Forbidden',
                Response::HTTP_FORBIDDEN,
            );
        }

        $csrf = $request->headers->get('X-CSRF-Token', '');

        if (
            $csrf === ''
            || !$csrfTokenManager->isTokenValid(
                new CsrfToken('management_write', $csrf),
            )
        ) {
            return self::error(
                'Invalid CSRF token',
                Response::HTTP_FORBIDDEN,
            );
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return self::error(
                'Content-Type must be application/json',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        try {
            $data = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return self::error(
                'Malformed JSON body',
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (
            !is_array($data)
            || array_is_list($data)
            || array_diff(
                array_keys($data),
                ['slug', 'name'],
            ) !== []
        ) {
            return self::error(
                'Invalid organization payload',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $slug = $data['slug'] ?? null;
        $name = $data['name'] ?? null;

        if (!is_string($slug) || !is_string($name)) {
            return self::error(
                'Invalid organization fields',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $name = trim($name);

        if (
            preg_match(
                '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                $slug,
            ) !== 1
            || $name === ''
            || mb_strlen($name) > 150
        ) {
            return self::error(
                'Invalid organization fields',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $subject = $user->getUserIdentifier();

        if (
            trim($subject) !== $subject
            || strlen($subject) > 255
        ) {
            return self::error(
                'Invalid authenticated subject',
                Response::HTTP_FORBIDDEN,
            );
        }

        $existing = $entityManager
            ->getRepository(Organization::class)
            ->findOneBy(['slug' => $slug]);

        if ($existing !== null) {
            return self::error(
                'Organization slug already exists',
                Response::HTTP_CONFLICT,
            );
        }

        $organization = new Organization($slug, $name);

        $membership = new OrganizationMembership(
            $organization,
            $subject,
            'admin',
        );

        $entityManager->persist($organization);
        $entityManager->persist($membership);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return self::error(
                'Organization slug already exists',
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(
            [
                'id' => $organization->getId(),
                'slug' => $organization->getSlug(),
                'name' => $organization->getName(),
                'role' => $membership->getRole(),
                'canManage' => true,
                'createdAt' => $organization
                    ->getCreatedAt()
                    ->format(DATE_ATOM),
            ],
            Response::HTTP_CREATED,
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
