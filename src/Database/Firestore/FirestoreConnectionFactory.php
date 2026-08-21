<?php

declare(strict_types=1);

namespace PhpFirebase\Database\Firestore;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\CredentialsLoader;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\Middleware\AuthTokenMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;

/**
 * Builds an authenticated {@see FirestoreConnection}.
 *
 * Credentials come from google/auth, so the usual options apply: a service
 * account key file, or Application Default Credentials when running on Google
 * infrastructure or after `gcloud auth application-default login`.
 *
 * This class is the convenience path and is the only part of the library that
 * needs google/auth and Guzzle. To avoid both, construct a
 * {@see FirestoreConnection} yourself with any PSR-18 client that attaches an
 * OAuth2 bearer token for the {@see self::SCOPE} scope.
 */
final class FirestoreConnectionFactory
{
    /**
     * The scope Firestore's REST API is served under.
     */
    public const string SCOPE = 'https://www.googleapis.com/auth/datastore';

    /**
     * Uses Application Default Credentials, which is what you want on Cloud
     * Run, App Engine, GCE, or a developer machine that has run
     * `gcloud auth application-default login`.
     */
    public static function fromApplicationDefaultCredentials(
        string $projectId,
        string $databaseId = '(default)',
    ): FirestoreConnection {
        return self::fromCredentials(
            ApplicationDefaultCredentials::getCredentials(self::SCOPE),
            $projectId,
            $databaseId,
        );
    }

    /**
     * Uses a service account key file. The project id is read from the file
     * unless you pass one.
     */
    public static function fromServiceAccount(
        string $keyFilePath,
        ?string $projectId = null,
        string $databaseId = '(default)',
    ): FirestoreConnection {
        if (!is_readable($keyFilePath)) {
            throw new InvalidArgumentException(sprintf('The service account file "%s" cannot be read.', $keyFilePath));
        }

        $contents = file_get_contents($keyFilePath);

        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('The service account file "%s" cannot be read.', $keyFilePath));
        }

        $key = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($key)) {
            throw new InvalidArgumentException(sprintf('The service account file "%s" is not a JSON object.', $keyFilePath));
        }

        /** @var array<string, mixed> $key */
        $projectId ??= is_string($key['project_id'] ?? null) ? $key['project_id'] : null;

        if ($projectId === null) {
            throw new InvalidArgumentException('No project id was given and the service account file has no "project_id".');
        }

        return self::fromCredentials(
            CredentialsLoader::makeCredentials(self::SCOPE, $key),
            $projectId,
            $databaseId,
        );
    }

    public static function fromCredentials(
        FetchAuthTokenInterface $credentials,
        string $projectId,
        string $databaseId = '(default)',
    ): FirestoreConnection {
        $stack = HandlerStack::create();
        $stack->push(new AuthTokenMiddleware($credentials));

        $factory = new HttpFactory();

        return new FirestoreConnection(
            new Client(['handler' => $stack, 'auth' => 'google_auth', 'http_errors' => false]),
            $factory,
            $factory,
            $projectId,
            $databaseId,
        );
    }
}
