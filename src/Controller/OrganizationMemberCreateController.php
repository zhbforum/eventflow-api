<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\ManagementApiGuard;
use App\Security\OidcUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OrganizationMemberCreateController
{
    #[Route(
        '/api/v1/organizations/{id}/members',
        name: 'api_organizations_members_create',
        methods: ['POST'],
        format: 'json',
    )]
    public function __invoke(
        string $id,
        Request $request,
        ManagementApiGuard $guard,
        Security $security,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $denied = $guard->check($request);

        if ($denied !== null) {
            return $denied;
        }

        if (
            $request->attributes->getString(
                'eventflow.organization_id',
            ) !== $id
        ) {
            return self::error(
                'Organization not found',
                Response::HTTP_NOT_FOUND,
            );
        }

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
            || count($data) !== 2
            || array_diff(
                array_keys($data),
                ['oidcSubject', 'role'],
            ) !== []
        ) {
            return self::error(
                'Invalid membership payload',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $targetSubject = $data['oidcSubject'] ?? null;
        $role = $data['role'] ?? null;

        if (
            !is_string($targetSubject)
            || $targetSubject === ''
            || trim($targetSubject) !== $targetSubject
            || strlen($targetSubject) > 255
            || !is_string($role)
            || !in_array($role, ['admin', 'viewer'], true)
        ) {
            return self::error(
                'Invalid membership fields',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $actorSubject = $user->getUserIdentifier();

        try {
            return $entityManager->getConnection()->transactional(
                static function (Connection $connection) use (
                    $entityManager,
                    $id,
                    $actorSubject,
                    $targetSubject,
                    $role,
                ): JsonResponse {
                    $organizationId = $connection->fetchOne(
                        'SELECT id
                         FROM organizations
                         WHERE id = :organization
                         FOR UPDATE',
                        ['organization' => $id],
                    );

                    if ($organizationId === false) {
                        return self::error(
                            'Organization not found',
                            Response::HTTP_NOT_FOUND,
                        );
                    }

                    $actorRole = $connection->fetchOne(
                        'SELECT role
                         FROM organization_memberships
                         WHERE organization_id = :organization
                           AND oidc_subject = :subject',
                        [
                            'organization' => $id,
                            'subject' => $actorSubject,
                        ],
                    );

                    if ($actorRole !== 'admin') {
                        return self::error(
                            'Forbidden',
                            Response::HTTP_FORBIDDEN,
                        );
                    }

                    $existing = $connection->fetchOne(
                        'SELECT id
                         FROM organization_memberships
                         WHERE organization_id = :organization
                           AND oidc_subject = :subject',
                        [
                            'organization' => $id,
                            'subject' => $targetSubject,
                        ],
                    );

                    if ($existing !== false) {
                        return self::error(
                            'Organization membership already exists',
                            Response::HTTP_CONFLICT,
                        );
                    }

                    $organization = $entityManager->find(
                        Organization::class,
                        $id,
                    );

                    if (!$organization instanceof Organization) {
                        return self::error(
                            'Organization not found',
                            Response::HTTP_NOT_FOUND,
                        );
                    }

                    $membership = new OrganizationMembership(
                        $organization,
                        $targetSubject,
                        $role,
                    );

                    $entityManager->persist($membership);
                    $entityManager->flush();

                    return new JsonResponse(
                        [
                            'id' => $membership->getId(),
                            'oidcSubject' => $membership->getOidcSubject(),
                            'role' => $membership->getRole(),
                            'createdAt' => $membership
                                ->getCreatedAt()
                                ->format(DATE_ATOM),
                        ],
                        Response::HTTP_CREATED,
                        ['Cache-Control' => 'no-store'],
                    );
                },
            );
        } catch (UniqueConstraintViolationException) {
            return self::error(
                'Organization membership already exists',
                Response::HTTP_CONFLICT,
            );
        }
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
