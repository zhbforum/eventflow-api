<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OrganizationMembership;
use App\Security\ManagementApiGuard;
use App\Security\OidcUser;
use App\Security\OrganizationMembershipResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OrganizationMemberListController
{
    #[Route(
        '/api/v1/organizations/{id}/members',
        name: 'api_organizations_members_list',
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(
        string $id,
        Request $request,
        ManagementApiGuard $guard,
        Security $security,
        OrganizationMembershipResolver $membershipResolver,
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

        $membership = $membershipResolver->resolve($user, $id);

        if ($membership === null || !$membership->isAdmin()) {
            return self::error(
                'Forbidden',
                Response::HTTP_FORBIDDEN,
            );
        }

        $page = filter_var(
            $request->query->get('page', '1'),
            FILTER_VALIDATE_INT,
            ['options' => [
                'min_range' => 1,
                'max_range' => 100000,
            ]],
        );

        $limit = filter_var(
            $request->query->get('limit', '20'),
            FILTER_VALIDATE_INT,
            ['options' => [
                'min_range' => 1,
                'max_range' => 100,
            ]],
        );

        if ($page === false || $limit === false) {
            return self::error(
                'Invalid pagination parameters',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $queryBuilder = $entityManager
            ->getRepository(OrganizationMembership::class)
            ->createQueryBuilder('m')
            ->andWhere('m.organization = :organization')
            ->setParameter('organization', $id);

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<OrganizationMembership> $memberships */
        $memberships = $queryBuilder
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new JsonResponse(
            [
                'items' => array_map(
                    static fn(
                        OrganizationMembership $member,
                    ): array => [
                        'id' => $member->getId(),
                        'oidcSubject' => $member->getOidcSubject(),
                        'role' => $member->getRole(),
                        'createdAt' => $member
                            ->getCreatedAt()
                            ->format(DATE_ATOM),
                    ],
                    $memberships,
                ),
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
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
