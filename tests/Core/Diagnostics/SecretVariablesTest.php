<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\SecretVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretVariablesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function secrets(): iterable
    {
        foreach (['masterpassword' => 'replication_password', 'validate_password.length' => 'validate_password.length', 'ssl_key' => 'ssl_key', 'ssl_key_file' => 'ssl_key_file', 'ssl_passphrase_command' => 'ssl_passphrase_command', 'api token' => 'my_api_token', 'secret' => 'cluster_secret', 'caps' => 'SSL_PASSWORD', 'encryption key' => 'encryption_key_id', 'default_password_lifetime' => 'default_password_lifetime', 'private key' => 'tls_private_key_path', 'keyring' => 'keyring_file_data', 'pwd' => 'db_pwd', 'credentials' => 'aws_credentials'] as $label => $name) {
            yield $label => [$name];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinary(): iterable
    {
        foreach (['key_buffer_size', 'key_cache_block_size', 'sql_require_primary_key', 'foreign_key_checks', 'max_connections', 'innodb_buffer_pool_size', 'tokenizer_mode_not', 'wal_keep_size', 'max_sort_length', 'keepalive_interval'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('secrets')]
    public function testANameThatSuggestsASecretIsMasked(string $name): void
    {
        self::assertTrue(SecretVariables::matches($name));
    }

    #[DataProvider('ordinary')]
    public function testAnOrdinaryNameIsNot(string $name): void
    {
        self::assertFalse(SecretVariables::matches($name));
    }
}
