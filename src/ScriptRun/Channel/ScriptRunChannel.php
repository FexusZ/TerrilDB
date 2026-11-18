<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

/**
 * Le format du message entre la requête web et le worker : « signature, saut de ligne, JSON, saut de ligne ». La signature (HMAC-SHA-256
 * avec une clé tirée du secret de l'installation, que l'application et le worker partagent) prouve que le message vient de
 * l'application : le dossier du socket est déjà en 0700, la signature ferme le reste (un autre processus de la machine qui l'atteindrait).
 */
final readonly class ScriptRunChannel
{
    private string $key;

    public function __construct(#[\SensitiveParameter] string $secret)
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('The channel needs the secret of the installation.');
        }
        $this->key = hash_hmac('sha256', 'terrildb-script-run-channel', $secret, true);
    }

    public function frame(#[\SensitiveParameter] string $json): string
    {
        return $this->mac($json)."\n".$json."\n";
    }

    /**
     * Le JSON d'un message authentique, sinon null (jamais d'exception : un message refusé n'apprend rien à celui qui l'envoie).
     */
    public function open(#[\SensitiveParameter] string $frame): ?string
    {
        if (1 !== preg_match('/^([0-9a-f]{64})\n(.*)\n$/sD', $frame, $m)) {
            return null;
        }

        return hash_equals($this->mac($m[2]), $m[1]) ? $m[2] : null;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '***'];
    }

    private function mac(#[\SensitiveParameter] string $json): string
    {
        return hash_hmac('sha256', $json, $this->key);
    }
}
