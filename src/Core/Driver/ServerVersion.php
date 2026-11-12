<?php

declare(strict_types=1);

namespace App\Core\Driver;

/**
 * La version rapportée par le serveur à l'ouverture (`SELECT VERSION()`, `SHOW server_version`, `INFO server`…), lue en
 * trois nombres. Valeur pure : aucune dépendance aux drivers.
 */
final readonly class ServerVersion
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ServerFlavour $flavour = ServerFlavour::Standard,
    ) {
    }

    /**
     * « 8.4.5 », « 11.8.2-MariaDB-ubu2404 », « 5.5.5-10.11.6-MariaDB » (préfixe de réplication de MariaDB : la vraie version
     * suit), « 8.0.30-Vitess », « 18.1 (Debian …) », « 26.10.1.123 ». Sans nombre, rien.
     */
    public static function parse(string $text): ?self
    {
        $text = trim($text);
        $flavour = ServerFlavour::Standard;
        if (1 === preg_match('/mariadb/i', $text)) {
            $flavour = ServerFlavour::MariaDb;
            $text = preg_replace('/^5\.5\.5-/', '', $text) ?? $text;
        } elseif (1 === preg_match('/vitess/i', $text)) {
            $flavour = ServerFlavour::Vitess;
        }
        if (1 !== preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $text, $m)) {
            return null;
        }

        return new self((int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), $flavour);
    }

    public function isAtLeast(int $major, int $minor = 0, int $patch = 0): bool
    {
        return [$this->major, $this->minor, $this->patch] >= [$major, $minor, $patch];
    }
}
