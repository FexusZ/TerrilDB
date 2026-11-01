<?php

declare(strict_types=1);

namespace App\Http\Json;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Les routes /web répondent toujours en JSON. Les erreurs du domaine sortent par code stable, avant la
 * journalisation : un mauvais mot de passe n'est pas un incident. Les erreurs HTTP gardent leur statut, et,
 * hors mode debug, toute autre erreur devient un 500 sans détail (après journalisation).
 */
final readonly class JsonExceptionListener
{
    public function __construct(
        private ErrorMapper $mapper,
        #[Autowire('%kernel.debug%')] private bool $debug,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function onDomainError(ExceptionEvent $event): void
    {
        if (!self::concerns($event)) {
            return;
        }

        $mapped = $this->mapper->map($event->getThrowable());
        if (null !== $mapped) {
            $event->setResponse(self::respond($mapped));
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
    public function onOtherError(ExceptionEvent $event): void
    {
        if (!self::concerns($event)) {
            return;
        }

        $error = $event->getThrowable();
        if ($error instanceof HttpExceptionInterface) {
            $event->setResponse(self::respond(new ErrorResponse($error->getStatusCode(), match ($error->getStatusCode()) {
                404 => 'not_found',
                405 => 'method_not_allowed',
                default => 'http_error',
            }, [], self::headers($error->getHeaders()))));
        } elseif (!$this->debug) {
            $event->setResponse(self::respond(new ErrorResponse(500, 'internal_error')));
        }
    }

    private static function concerns(ExceptionEvent $event): bool
    {
        $path = $event->getRequest()->getPathInfo();

        return str_starts_with($path, '/web/') || str_starts_with($path, '/api/');
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $strings = [];
        foreach ($headers as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $strings[$name] = $value;
            }
        }

        return $strings;
    }

    private static function respond(ErrorResponse $error): JsonResponse
    {
        return new JsonResponse(['error' => $error->code] + $error->extra, $error->status, $error->headers);
    }
}
