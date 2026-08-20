<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use DateTimeImmutable;
use PhpFirebase\Entity;

final class User extends Entity
{
    public string $firstName = '';

    public string $lastName = '';

    public ?int $age = null;

    public Role $role = Role::Member;

    public ?Level $level = null;

    public ?DateTimeImmutable $joinedAt = null;

    /** Written as a sentinel, read back as whatever the server resolved it to. */
    public mixed $updatedAt = null;

    public ?Address $address = null;

    /** @var list<string> */
    public array $tags = [];

    protected string $note = '';

    private string $secret = 'not persisted';

    public static function named(string $firstName, string $lastName): self
    {
        $user = new self();
        $user->firstName = $firstName;
        $user->lastName = $lastName;

        return $user;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function withNote(string $note): self
    {
        $clone = clone $this;
        $clone->note = $note;

        return $clone;
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
