<?php

declare(strict_types=1);

namespace PhpFirebase\Exception;

use RuntimeException;

final class EntityNotFound extends RuntimeException implements PhpFirebaseException
{
    public static function withId(string $entityClass, string $path, string $id): self
    {
        return new self(sprintf('No %s found at "%s/%s".', $entityClass, $path, $id));
    }
}
