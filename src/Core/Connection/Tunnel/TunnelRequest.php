<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Connection\SshJump;
use App\Core\Connection\TransportRules;

/**
 * Ce qu'on demande au conteneur du tunnel : le bastion (avec sa clé, qui ne vit qu'en mémoire), la cible vue du
 * bastion, le nom du socket à créer (un client PostgreSQL attend `.s.PGSQL.<port>` dans son dossier) et le délai
 * d'inactivité au bout duquel le tunnel se ferme.
 */
final readonly class TunnelRequest
{
    public const IDLE_SECONDS = 300;

    public function __construct(
        public SshJump $jump,
        public string $targetHost,
        public int $targetPort,
        public string $socketName = 'tunnel.sock',
        public int $idleSeconds = self::IDLE_SECONDS,
    ) {
        TransportRules::assertHost($targetHost, 'target');
        TransportRules::assertPort($targetPort, 'target');
        if (1 !== preg_match('/^(tunnel\.sock|\.s\.PGSQL\.\d{1,5})$/D', $socketName)) {
            throw new InvalidConnectionParametersException('The socket name is not valid.');
        }
        if ($idleSeconds < 1 || $idleSeconds > self::IDLE_SECONDS) {
            throw new InvalidConnectionParametersException('The idle delay must be between 1 and 300 seconds.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['jump' => $this->jump->host, 'target' => $this->targetHost.':'.$this->targetPort, 'socketName' => $this->socketName];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('A tunnel request must not be serialized.');
    }
}
