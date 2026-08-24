<?php

declare(strict_types=1);

namespace PhpFirebase\Exception;

use CuyZ\Valinor\Mapper\MappingError;
use InvalidArgumentException;

final class InvalidEntity extends InvalidArgumentException implements PhpFirebaseException
{
    public static function unknownClass(string $class): self
    {
        return new self(sprintf('There is no class named %s.', $class));
    }

    public static function notAnEntity(string $class): self
    {
        return new self(sprintf('%s does not implement %s.', $class, \PhpFirebase\EntityInterface::class));
    }

    public static function unexpectedClass(string $expected, string $actual): self
    {
        return new self(sprintf('Expected an instance of %s, got %s.', $expected, $actual));
    }

    public static function missingId(string $class): self
    {
        return new self(sprintf('The %s instance has no id.', $class));
    }

    /**
     * @param list<string> $properties
     */
    public static function partiallyConstructed(string $class, array $properties): self
    {
        return new self(sprintf(
            '%s declares a constructor that does not cover %s, so reading a record would silently leave '
            .'%s at their defaults and the next save would overwrite the stored values. Either give the class '
            .'no constructor and let its properties be filled directly, or promote every persisted property '
            .'into the constructor.',
            $class,
            implode(', ', array_map(static fn (string $p): string => '$'.$p, $properties)),
            count($properties) === 1 ? 'it' : 'them',
        ));
    }

    public static function constructorNotPublic(string $class): self
    {
        return new self(sprintf(
            '%s has a non-public constructor, which leaves no way to build it. Make it public, or remove it '
            .'so its properties can be filled directly.',
            $class,
        ));
    }

    public static function mappingFailed(string $class, MappingError $error): self
    {
        $reasons = [];

        foreach ($error->messages()->errors() as $message) {
            $reasons[] = sprintf('%s: %s', $message->path(), $message->toString());
        }

        return new self(
            sprintf('Cannot build a %s from the stored record. %s', $class, implode(' ', $reasons)),
            previous: $error,
        );
    }

    public static function cannotHydrate(string $class, string $property, string $reason): self
    {
        return new self(sprintf('Cannot hydrate %s::$%s: %s', $class, $property, $reason));
    }

    public static function notAnObjectMap(string $class, string $path): self
    {
        return new self(sprintf(
            'Expected the value at "%s" to be a map of %s records, got a scalar.',
            $path,
            $class,
        ));
    }
}
