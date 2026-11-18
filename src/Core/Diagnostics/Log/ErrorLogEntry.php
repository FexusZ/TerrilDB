<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Log;

/**
 * Une ligne du journal d'erreurs du serveur. Le message est masqué puis raccourci ici, à la construction (`LogMessage`) : il peut
 * citer un compte, une adresse ou une valeur. Le code d'erreur du moteur est un mot-clé (`MY-010116`), jamais du texte libre.
 */
final readonly class ErrorLogEntry
{
    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D';

    public string $level;
    public ?string $code;
    public ?string $subsystem;
    public ?string $message;

    public function __construct(
        /** Instant de la ligne (secondes Unix). */
        public ?float $loggedAt,
        string $level,
        ?string $subsystem,
        ?string $code,
        ?string $message,
    ) {
        $this->level = strtolower(trim($level));
        $this->subsystem = null !== $subsystem && 1 === preg_match('/^[A-Za-z0-9_ .-]{1,32}$/D', $subsystem) ? $subsystem : null;
        $this->code = null !== $code && 1 === preg_match(self::CODE_PATTERN, $code) ? $code : null;
        $this->message = LogMessage::of($message);
    }
}
