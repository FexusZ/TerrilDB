<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Tests\Integration\Driver\TargetDatabase;

/** Un compte jetable créé par TestAccounts : de quoi se connecter (ou constater qu'on ne le peut pas) avec ses droits. */
final readonly class TestAccount
{
    public function __construct(
        public string $env,
        public string $name,
        public string $host,
        public string $password,
    ) {
    }

    /** Nom d'une table de la base de test tel que ce compte doit l'écrire (qualifié côté MySQL, où `connect()` ne choisit pas de base). */
    public function qualify(string $table): string
    {
        return TestAccounts::isPostgres($this->env) ? $table : TargetDatabase::parameters($this->env)->database.'.'.$table;
    }

    /**
     * Ouvre une session avec CE compte, sur la même cible et la même base que les tests. Un compte `'x'@'localhost'` n'est pas
     * joignable depuis un autre conteneur (la connexion arrive du réseau) : on ne se sert alors que de son existence et de ses droits.
     */
    public function connect(): \PDO
    {
        $target = TargetDatabase::parameters($this->env);
        $pg = TestAccounts::isPostgres($this->env);
        // MySQL refuse la connexion « sur » une base où le compte n'a aucun droit : pas de base choisie, les tables sont qualifiées.
        $dsn = \sprintf('%s:host=%s;port=%d', $pg ? 'pgsql' : 'mysql', $target->host, $target->port);
        $dsn .= $pg ? \sprintf(';dbname=%s', $target->database) : '';

        return new \PDO($dsn, $this->name, $this->password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
