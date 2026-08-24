<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Firestore;

use InvalidArgumentException;
use JsonException;
use PhpFirebase\Database\Firestore\FirestoreConnection;
use PhpFirebase\Database\Firestore\FirestoreConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FirestoreConnectionFactory::class)]
final class FirestoreConnectionFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    private function writeKeyFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'php-firebase-key');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }

    /**
     * A structurally valid service account. The key is a throwaway generated
     * for this test and authenticates nothing.
     */
    private function serviceAccount(bool $withProjectId = true): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);

        $account = [
            'type' => 'service_account',
            'client_email' => 'test@test-project.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ];

        if ($withProjectId) {
            $account['project_id'] = 'test-project';
        }

        return json_encode($account, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_builds_a_connection_from_a_service_account_file(): void
    {
        $connection = FirestoreConnectionFactory::fromServiceAccount($this->writeKeyFile($this->serviceAccount()));

        $this->assertInstanceOf(FirestoreConnection::class, $connection);
    }

    #[Test]
    public function an_explicit_project_id_is_accepted_when_the_file_has_none(): void
    {
        $connection = FirestoreConnectionFactory::fromServiceAccount(
            $this->writeKeyFile($this->serviceAccount(withProjectId: false)),
            'other-project',
        );

        $this->assertInstanceOf(FirestoreConnection::class, $connection);
    }

    #[Test]
    public function it_reports_a_missing_file_clearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be read');

        FirestoreConnectionFactory::fromServiceAccount('/no/such/service-account.json');
    }

    #[Test]
    public function it_reports_a_file_that_is_not_json(): void
    {
        $this->expectException(JsonException::class);

        FirestoreConnectionFactory::fromServiceAccount($this->writeKeyFile('not json at all'));
    }

    #[Test]
    public function it_reports_a_file_that_is_not_a_json_object(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a JSON object');

        FirestoreConnectionFactory::fromServiceAccount($this->writeKeyFile('"a string"'));
    }

    #[Test]
    public function it_insists_on_a_project_id_from_somewhere(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('project_id');

        FirestoreConnectionFactory::fromServiceAccount($this->writeKeyFile($this->serviceAccount(withProjectId: false)));
    }
}
