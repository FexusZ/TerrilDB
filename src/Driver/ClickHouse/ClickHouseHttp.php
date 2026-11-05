<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Connection\ConnectionParameters;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Le dialogue avec un serveur ClickHouse par son interface HTTP (aucun pilote DBAL n'existe pour lui).
 *
 * L'instruction part dans le corps d'un POST, les réglages et les paramètres liés (`{nom:Type}`) dans l'adresse,
 * les identifiants dans des en-têtes : ils ne figurent jamais dans une URL qui pourrait être journalisée. Les
 * ports 8443 et 443 parlent TLS. Les erreurs sont normalisées : jamais d'adresse ni d'identifiant dans un message.
 */
final readonly class ClickHouseHttp
{
    private const SECURE_PORTS = [443, 8443];
    // Marge au-dessus du délai demandé au moteur : c'est lui qui doit arrêter la requête, avec son propre message.
    private const HTTP_MARGIN_SECONDS = 5.0;

    public function __construct(private HttpClientInterface $client, private ConnectionParameters $parameters)
    {
    }

    /**
     * @param array<string, bool|float|int|string> $settings réglages du moteur (readonly, max_execution_time…)
     * @param array<string, string>                $params   valeurs des paramètres liés de l'instruction
     *
     * @throws ClickHouseException
     */
    public function query(string $sql, array $settings = [], array $params = [], float $timeoutSeconds = 30.0): ClickHouseResponse
    {
        $query = ['default_format' => 'JSONCompact', 'database' => $this->parameters->database] + $settings;
        foreach ($params as $name => $value) {
            $query['param_'.$name] = $value;
        }

        try {
            $response = $this->client->request('POST', $this->baseUrl().'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986), [
                // Adresse déjà contrôlée : l'URL garde le nom (en-tête Host, TLS) mais la connexion part vers cette adresse.
                'resolve' => $this->parameters->address() === $this->parameters->host ? [] : [trim($this->parameters->host, '[]') => $this->parameters->address()],
                'headers' => [
                    'X-ClickHouse-User: '.$this->parameters->username,
                    'X-ClickHouse-Key: '.$this->parameters->password(),
                ],
                'body' => $sql,
                'timeout' => $timeoutSeconds + self::HTTP_MARGIN_SECONDS,
                'max_duration' => $timeoutSeconds + self::HTTP_MARGIN_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new ClickHouseException('The database could not be reached.', previous: $e);
        }

        if ($status >= 400) {
            throw $this->failure($response, $body);
        }

        return $this->decode($response, $body);
    }

    private function baseUrl(): string
    {
        $scheme = \in_array($this->parameters->port, self::SECURE_PORTS, true) ? 'https' : 'http';
        $host = str_contains($this->parameters->host, ':') && !str_starts_with($this->parameters->host, '[')
            ? '['.$this->parameters->host.']'
            : $this->parameters->host;

        return \sprintf('%s://%s:%d/', $scheme, $host, $this->parameters->port);
    }

    private function failure(ResponseInterface $response, string $body): ClickHouseException
    {
        $header = $response->getHeaders(false)['x-clickhouse-exception-code'][0] ?? null;
        $code = null !== $header && ctype_digit($header) ? (int) $header : null;

        // « Code: 62. DB::Exception: <message> (NOM_DE_CODE) (version …) » : on garde le message et le nom du code.
        $message = trim($body);
        $message = (string) preg_replace('/^Code:\s*\d+\.\s*DB::Exception:\s*/', '', $message);
        $message = (string) preg_replace('/\s*\(version [^)]*(\([^)]*\))?[^)]*\)\s*$/', '', $message);
        if ('' === $message) {
            $message = 'The database refused the statement.';
        }

        return new ClickHouseException($message, $code);
    }

    private function decode(ResponseInterface $response, string $body): ClickHouseResponse
    {
        $written = 0;
        $summary = $response->getHeaders(false)['x-clickhouse-summary'][0] ?? null;
        if (null !== $summary) {
            $decoded = json_decode($summary, true);
            $count = \is_array($decoded) ? ($decoded['written_rows'] ?? 0) : 0;
            $written = is_numeric($count) ? (int) $count : 0;
        }

        if ('' === trim($body)) {
            return new ClickHouseResponse([], [], [], $written);
        }

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ClickHouseException('The database answered in an unexpected format.', previous: $e);
        }
        if (!\is_array($data) || !\is_array($data['meta'] ?? null) || !\is_array($data['data'] ?? null)) {
            throw new ClickHouseException('The database answered in an unexpected format.');
        }

        $columns = [];
        $types = [];
        foreach ($data['meta'] as $column) {
            $name = \is_array($column) ? ($column['name'] ?? null) : null;
            $type = \is_array($column) ? ($column['type'] ?? null) : null;
            if (!\is_string($name) || !\is_string($type)) {
                throw new ClickHouseException('The database answered in an unexpected format.');
            }
            $columns[] = $name;
            $types[] = $type;
        }
        $rows = [];
        foreach ($data['data'] as $row) {
            $rows[] = \is_array($row) ? array_values($row) : throw new ClickHouseException('The database answered in an unexpected format.');
        }

        return new ClickHouseResponse($columns, $types, $rows, $written);
    }
}
