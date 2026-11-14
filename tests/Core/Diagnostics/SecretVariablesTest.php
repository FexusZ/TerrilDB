<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\SecretVariables;
use App\Core\Diagnostics\ServerVariable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretVariablesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function secrets(): iterable
    {
        foreach (['masterpassword' => 'replication_password', 'validate_password.length' => 'validate_password.length', 'ssl_key' => 'ssl_key', 'ssl_key_file' => 'ssl_key_file', 'ssl_passphrase_command' => 'ssl_passphrase_command', 'api token' => 'my_api_token', 'secret' => 'cluster_secret', 'caps' => 'SSL_PASSWORD', 'encryption key' => 'encryption_key_id', 'default_password_lifetime' => 'default_password_lifetime', 'private key' => 'tls_private_key_path', 'keyring' => 'keyring_file_data', 'pwd' => 'db_pwd', 'credentials' => 'aws_credentials', 'conninfo' => 'primary_conninfo', 'archive' => 'archive_command', 'archive cleanup' => 'archive_cleanup_command', 'restore' => 'restore_command', 'recovery end' => 'recovery_end_command', 'galera sst' => 'wsrep_sst_auth', 'galera notify' => 'wsrep_notify_cmd', 'galera options' => 'wsrep_provider_options', 'init connect' => 'init_connect', 'init slave' => 'init_slave', 'init replica' => 'init_replica', 'url' => 'format_avro_schema_registry_url', 'redirect' => 'redirect_url', 'url base' => 'url_base', 'dsn' => 'replica_dsn', 'uri' => 'broker_uri', 'case' => 'PRIMARY_CONNINFO'] as $label => $name) {
            yield $label => [$name];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinary(): iterable
    {
        foreach (['key_buffer_size', 'key_cache_block_size', 'sql_require_primary_key', 'foreign_key_checks', 'max_connections', 'innodb_buffer_pool_size', 'tokenizer_mode_not', 'wal_keep_size', 'max_sort_length', 'keepalive_interval', 'authentication_timeout', 'init_file', 'command_timeout_not', 'http_max_uri_size', 'allow_url_wildcard_from_index_pages', 'ssl_ciphers', 'wal_level', 'archive_mode', 'archive_timeout', 'recovery_target_time'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leakingValues(): iterable
    {
        yield 'libpq' => ['host=primary port=5432 user=repl password=hunter2 application_name=r1'];
        yield 'libpq uppercase' => ['HOST=x PASSWORD = hunter2'];
        yield 'url with credentials' => ['postgresql://repl:hunter2@primary:5432/db'];
        yield 'url with a user only' => ['https://token@registry.example.test/schema'];
        yield 'pwd' => ['user=a pwd=b'];
        yield 'command with a secret' => ['mysql -u x --password=hunter2'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainValues(): iterable
    {
        yield 'number' => ['128'];
        yield 'path' => ['/var/lib/postgresql/data'];
        yield 'url without credentials' => ['https://registry.example.test/schema'];
        yield 'email-less text' => ['SET NAMES utf8mb4'];
        yield 'on' => ['ON'];
    }

    #[DataProvider('leakingValues')]
    public function testAValueThatLooksLikeACredentialIsMaskedWhateverTheName(string $value): void
    {
        $variable = new ServerVariable('some_setting', $value, $value, true, null, null, null);

        self::assertTrue($variable->masked);
        self::assertNull($variable->value);
        self::assertNull($variable->default);
    }

    #[DataProvider('plainValues')]
    public function testAPlainValueIsKept(string $value): void
    {
        $variable = new ServerVariable('some_setting', $value, null, false, null, null, null);

        self::assertFalse($variable->masked);
        self::assertSame($value, $variable->value);
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
