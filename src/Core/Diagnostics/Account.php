<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Un compte (ou un rôle) d'un serveur SQL. Aucune empreinte de mot de passe : `passwordSet` dit seulement si le compte en a une.
 */
final readonly class Account
{
    /**
     * @param list<string>                $roles              rôles accordés directement (noms `utilisateur@hôte`, ou le nom seul pour un rôle sans hôte)
     * @param list<string>                $defaultRoles       rôles activés à la connexion
     * @param list<string>                $globalPrivileges   droits sur `*.*`, en majuscules
     * @param array<string, list<string>> $databasePrivileges droits par base
     * @param list<string>                $grantOptionOn      portées où le compte peut transmettre ses droits (`*.*` ou une base)
     * @param array<string, int>          $limits             limites non nulles (max_user_connections…)
     */
    public function __construct(
        public string $user,
        public string $host,
        public string $plugin = '',
        public bool $passwordSet = true,
        public bool $passwordExpired = false,
        public bool $locked = false,
        public TlsRequirement $tls = TlsRequirement::None,
        public bool $isRole = false,
        public bool $current = false,
        public array $roles = [],
        public array $defaultRoles = [],
        public array $globalPrivileges = [],
        public array $databasePrivileges = [],
        public array $grantOptionOn = [],
        public array $limits = [],
    ) {
    }

    public function name(): string
    {
        return '' === $this->host ? $this->user : $this->user.'@'.$this->host;
    }
}
