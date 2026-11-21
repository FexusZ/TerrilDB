<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Les flux VReplication dont une table du keyspace de la connexion est la SOURCE (Vitess seulement). Lu à la demande, jamais en cache :
 * un flux se crée et se termine sans prévenir. Une lecture qui échoue rend une liste vide (l'avertissement manque, la requête n'est jamais gênée).
 */
interface StreamSources
{
    /**
     * @return list<StreamSource>
     */
    public function sources(): array;
}
