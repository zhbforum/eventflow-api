<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OrganizationListController
{
    #[Route(
        '/api/v1/organizations',
        name: 'api_organizations_list',
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(
        Request $request,
        Security $security,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $security->getUser();

        if (
            !$user instanceof OidcUser
            || $request->headers->get('Authorization', '') !== ''
        ) {
            return self::forbidden();
        }

        $roles = $user->getRoles();

        $isGlobalAdmin = in_array(
            'ROLE_EVENTFLOW_ADMIN',
            $roles,
            true,
        );

        $isGlobalViewer = in_array(
            'ROLE_EVENTFLOW_VIEWER',
            $roles,
            true,
        );

        if (!$isGlobalAdmin && !$isGlobalViewer) {
            return self::forbidden();
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
            return new JsonResponse(
                ['error' => 'Invalid pagination parameters'],
                Response::HTTP_BAD_REQUEST,
                ['Cache-Control' => 'no-store'],
            );
        }

        $queryBuilder = $entityManager
            ->getRepository(OrganizationMembership::class)
            ->createQueryBuilder('m')
            ->innerJoin('m.organization', 'o')
            ->andWhere('m.oidcSubject = :subject')
            ->setParameter(
                'subject',
                $user->getUserIdentifier(),
            );

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<OrganizationMembership> $memberships */
        $memberships = $queryBuilder
            ->addSelect('o')
            ->orderBy('o.name', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new JsonResponse(
            [
                'items' => array_map(
                    static function (
                        OrganizationMembership $membership,
                    ) use ($isGlobalAdmin): array {
                        $organization = $membership
                            ->getOrganization();

                        return [
                            'id' => $organization->getId(),
                            'slug' => $organization->getSlug(),
                            'name' => $organization->getName(),
                            'role' => $membership->getRole(),
                            'canManage' => (
                                $isGlobalAdmin
                                && $membership->isAdmin()
                            ),
                            'createdAt' => $organization
                                ->getCreatedAt()
                                ->format(DATE_ATOM),
                        ];
                    },
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

    private static function forbidden(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Forbidden'],
            Response::HTTP_FORBIDDEN,
            ['Cache-Control' => 'no-store'],
        );
    }
}
