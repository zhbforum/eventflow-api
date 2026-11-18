<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\ManagementApiGuard;
use App\Security\OidcUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationMemberDeleteController
{
    #[Route(
        '/api/v1/organizations/{id}/members/{memberId}',
        name: 'api_organizations_members_delete',
        methods: ['DELETE'],
        format: 'json',
    )]
    public function __invoke(
        string $id,
        string $memberId,
        Request $request,
        ManagementApiGuard $guard,
        Security $security,
        EntityManagerInterface $entityManager,
    ): Response {
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

        if (!Uuid::isValid($memberId)) {
            return self::error(
                'Membership not found',
                Response::HTTP_NOT_FOUND,
            );
        }

        $subject = $user->getUserIdentifier();

        return $entityManager->getConnection()->transactional(
            static function (Connection $connection) use (
                $id,
                $memberId,
                $subject,
            ): Response {
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
                        'subject' => $subject,
                    ],
                );

                if ($actorRole !== 'admin') {
                    return self::error(
                        'Forbidden',
                        Response::HTTP_FORBIDDEN,
                    );
                }

                $targetRole = $connection->fetchOne(
                    'SELECT role
                     FROM organization_memberships
                     WHERE id = :member
                       AND organization_id = :organization',
                    [
                        'member' => $memberId,
                        'organization' => $id,
                    ],
                );

                if ($targetRole === false) {
                    return self::error(
                        'Membership not found',
                        Response::HTTP_NOT_FOUND,
                    );
                }

                if ($targetRole === 'admin') {
                    $adminCount = (int) $connection->fetchOne(
                        'SELECT COUNT(*)
                         FROM organization_memberships
                         WHERE organization_id = :organization
                           AND role = :role',
                        [
                            'organization' => $id,
                            'role' => 'admin',
                        ],
                    );

                    if ($adminCount <= 1) {
                        return self::error(
                            'Cannot remove the last organization admin',
                            Response::HTTP_CONFLICT,
                        );
                    }
                }

                $connection->executeStatement(
                    'DELETE FROM organization_memberships
                     WHERE id = :member
                       AND organization_id = :organization',
                    [
                        'member' => $memberId,
                        'organization' => $id,
                    ],
                );

                return new Response(
                    '',
                    Response::HTTP_NO_CONTENT,
                    ['Cache-Control' => 'no-store'],
                );
            },
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
