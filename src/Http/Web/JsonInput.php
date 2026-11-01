<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Connection\Engine;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lecture typée du corps JSON d'une requête. Chaque accès refuse un champ absent ou du mauvais type, avec un
 * message qui nomme le champ.
 */
final readonly class JsonInput
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $body = $request->getContent();
        if ('' === trim($body)) {
            return new self([]);
        }

        try {
            $data = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestException('The request body is not valid JSON.');
        }
        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw new BadRequestException('The request body must be a JSON object.');
        }

        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return new self($typed);
    }

    public function string(string $field): string
    {
        $value = $this->data[$field] ?? null;

        return \is_string($value) ? $value : throw new BadRequestException(\sprintf('The field "%s" must be a string.', $field));
    }

    public function optionalString(string $field): ?string
    {
        return null === ($this->data[$field] ?? null) ? null : $this->string($field);
    }

    public function engine(string $field): Engine
    {
        return Engine::tryFrom($this->string($field)) ?? throw new InvalidConnectionParametersException('The engine is not supported.');
    }

    public function int(string $field): int
    {
        $value = $this->data[$field] ?? null;

        return \is_int($value) ? $value : throw new BadRequestException(\sprintf('The field "%s" must be an integer.', $field));
    }

    public function optionalInt(string $field, ?int $default = null): ?int
    {
        return null === ($this->data[$field] ?? null) ? $default : $this->int($field);
    }

    public function bool(string $field, ?bool $default = null): bool
    {
        $value = $this->data[$field] ?? null;
        if (null === $value && null !== $default) {
            return $default;
        }

        return \is_bool($value) ? $value : throw new BadRequestException(\sprintf('The field "%s" must be a boolean.', $field));
    }
}
