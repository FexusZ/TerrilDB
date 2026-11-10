<?php

declare(strict_types=1);

namespace App\Core\Connection;

/**
 * Le premier saut entre TerrilDB et la base : un bastion SSH ou un proxy SOCKS5. L'hôte et le port sont ceux de ce
 * saut (c'est ce que le serveur joint vraiment), pas ceux de la base ; la base reste dans `ConnectionParameters`.
 * Un transport fait partie des paramètres chiffrés avec la clé de l'utilisateur : ses secrets n'apparaissent jamais
 * dans un dump ni dans un journal.
 */
interface Transport
{
    public const HOST_MAX_LENGTH = 255;

    public string $host { get; }

    public int $port { get; }

    /**
     * Forme stockée dans le JSON chiffré (clé `type` comprise).
     *
     * @return array<string, int|string|null>
     */
    public function toArray(): array;
}
