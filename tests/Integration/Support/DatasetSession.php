<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Une connexion de jeu de données, la même pour les six cibles SQL (DBAL, ou l'interface HTTP de ClickHouse) : exécuter, lire une
 * valeur, lire des lignes. Les jeux de données créent des objets jetables nommés `tdb_ds_<hex>…` : `leftovers()` dit ce qui
 * reste sur une cible, pour que les tests vérifient qu'ils ne laissent rien.
 */
final class DatasetSession
{
    public const PREFIX = 'tdb_ds_';

    private ?Connection $dbal = null;
    private ?ClickHouseHttp $http = null;

    private function __construct(public readonly string $env, public readonly Engine $engine)
    {
        $parameters = TargetDatabase::parameters($env);
        if (Engine::ClickHouse === $engine) {
            $this->http = new ClickHouseHttp(HttpClient::create(), $parameters);
        } else {
            $this->dbal = (new DbalConnector())->connect($engine, $parameters);
        }
    }

    public static function open(string $env): self
    {
        return new self($env, TargetDatabase::engine($env));
    }

    /** Un nom d'objet jetable : préfixe, huit hexadécimaux, puis un suffixe lisible. */
    public static function name(string $suffix = ''): string
    {
        return self::PREFIX.bin2hex(random_bytes(4)).('' === $suffix ? '' : '_'.$suffix);
    }

    public function connection(): Connection
    {
        return $this->dbal ?? throw new \LogicException('ClickHouse has no DBAL connection.');
    }

    public function exec(string $sql): void
    {
        if (null !== $this->http) {
            $this->http->query($sql, timeoutSeconds: 120.0);

            return;
        }
        $this->connection()->executeStatement($sql);
    }

    /** @return list<list<mixed>> */
    public function rows(string $sql): array
    {
        if (null !== $this->http) {
            return $this->http->query($sql, timeoutSeconds: 120.0)->rows;
        }

        return $this->connection()->fetchAllNumeric($sql);
    }

    /** Première colonne de la première ligne, en texte. */
    public function scalar(string $sql): string
    {
        $value = $this->rows($sql)[0][0] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Les tables et vues jetables encore présentes dans la base de la connexion.
     *
     * @return list<string>
     */
    public function leftovers(): array
    {
        $sql = match ($this->engine) {
            Engine::ClickHouse => "SELECT name FROM system.tables WHERE database = currentDatabase() AND left(name, 7) = 'tdb_ds_' ORDER BY name",
            Engine::PostgreSql => "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND left(table_name, 7) = 'tdb_ds_' ORDER BY table_name",
            default => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, 7) = 'tdb_ds_' ORDER BY TABLE_NAME",
        };

        return array_map(static fn (array $row): string => \is_scalar($row[0]) ? (string) $row[0] : '', $this->rows($sql));
    }

    public function close(): void
    {
        $this->dbal?->close();
        $this->dbal = null;
    }
}
