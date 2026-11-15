<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class OidcUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private string $id,
        private string $username,
        private ?string $email,
        private array $roles,
        private int $authenticatedAt,
    ) {}

    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getAuthenticatedAt(): int
    {
        return $this->authenticatedAt;
    }

    public function eraseCredentials(): void {}
}
