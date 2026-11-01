<?php

declare(strict_types=1);

namespace App\Driver\Redis;

use App\Core\Guard\GuardReason;
use App\Core\Guard\RedisCommandLine;
use App\Core\Guard\StatementKind;
use App\Core\Query\CellValue;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
use App\Driver\PdoRows;
use Predis\Client;
use Predis\PredisException;
use Predis\TimeoutException;

/**
 * Exécute UNE commande Redis déjà examinée par le garde. Les arguments sont ceux de l'analyse (jamais du texte à
 * ré-interpréter). En lecture seule, le serveur a le dernier mot : avant d'envoyer, on lui demande (COMMAND INFO) comment
 * il classe la commande (et sa sous-commande), et on refuse tout ce qu'il marque comme écriture, administration,
 * bloquant ou publication. Chaque commande part sur un client neuf dont le délai de lecture est celui demandé : Predis
 * ne permet pas de le changer sur une connexion ouverte, et une réponse tardive désynchroniserait une connexion partagée.
 *
 * La réponse devient un tableau d'une colonne (une ligne par élément, les réponses imbriquées en JSON), sauf HGETALL
 * (champ, valeur) et INFO (section, clé, valeur).
 */
final readonly class RedisQueryRunner implements QueryRunner
{
    private const DENIED_FLAGS = ['write', 'admin', 'blocking', 'pubsub'];

    /**
     * @param \Closure(float): Client $clients ouvre un client dont le délai de lecture (en secondes) est donné
     */
    public function __construct(private \Closure $clients)
    {
    }

    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
    {
        $line = RedisCommandLine::parse($sql);
        $arguments = $line->commands[0] ?? null;
        if (!$line->complete || null === $arguments || 1 !== \count($line->commands)) {
            throw new QueryFailedException('Exactly one command is expected.');
        }

        $started = hrtime(true);
        $client = ($this->clients)($options->timeoutMs / 1000);

        try {
            $this->ensureAllowed($client, $arguments, $options->mode);
            $error = false;
            $reply = $client->executeRaw($arguments, $error);
        } catch (TimeoutException $e) {
            throw QueryTimeoutException::afterMilliseconds($options->timeoutMs, $e);
        } catch (PredisException $e) {
            throw new QueryFailedException('The database could not be reached.', previous: $e);
        } finally {
            $client->disconnect();
        }
        if ($error) {
            throw new QueryFailedException(RedisReplyException::fromReply(\is_string($reply) ? $reply : 'ERR unexpected reply')->getMessage());
        }

        return $this->result($arguments, $reply, $options->maxRows, $started);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws QueryRefusedException
     */
    private function ensureAllowed(Client $client, array $arguments, QueryMode $mode): void
    {
        if (QueryMode::ReadOnly !== $mode) {
            return;
        }

        // La commande seule, puis « commande|sous-commande » (CONFIG GET est de l'administration, pas CONFIG) : on
        // cumule les drapeaux, et une commande que le serveur ne connaît pas du tout est refusée.
        $lookups = [strtolower($arguments[0] ?? '')];
        if (isset($arguments[1])) {
            $lookups[] = $lookups[0].'|'.strtolower($arguments[1]);
        }
        $flags = [];
        $known = false;
        foreach ($lookups as $lookup) {
            $error = false;
            $info = $client->executeRaw(['COMMAND', 'INFO', $lookup], $error);
            $entry = \is_array($info) && !$error ? ($info[0] ?? null) : null;
            if (\is_array($entry)) {
                $known = true;
                array_push($flags, ...array_map(static fn (mixed $flag): string => strtolower(\is_scalar($flag) ? (string) $flag : ''), \is_array($entry[2] ?? null) ? $entry[2] : []));
            }
        }
        if (!$known) {
            throw new QueryRefusedException(GuardReason::UnsupportedStatement);
        }
        if ([] !== array_intersect($flags, self::DENIED_FLAGS)) {
            throw new QueryRefusedException(GuardReason::NotReadOnly);
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function result(array $arguments, mixed $reply, int $maxRows, int $started): QueryResult
    {
        $name = strtoupper($arguments[0] ?? '');

        if ('INFO' === $name && \is_string($reply)) {
            return $this->rows(['section', 'key', 'value'], self::infoRows($reply), $maxRows, $started);
        }
        if ('HGETALL' === $name && \is_array($reply)) {
            return $this->rows(['field', 'value'], array_map(
                static fn (array $pair): array => [self::cell($pair[0] ?? null), self::cell($pair[1] ?? null)],
                array_chunk(array_values($reply), 2),
            ), $maxRows, $started);
        }
        if (\is_array($reply)) {
            return $this->rows(['value'], array_map(static fn (mixed $item): array => [self::cell($item)], array_values($reply)), $maxRows, $started);
        }

        return $this->rows(['value'], [[self::cell($reply)]], $maxRows, $started);
    }

    /**
     * @param list<string>                           $columns
     * @param list<list<bool|float|int|string|null>> $rows
     */
    private function rows(array $columns, array $rows, int $maxRows, int $started): QueryResult
    {
        return QueryResult::rows($columns, \array_slice($rows, 0, $maxRows), \count($rows) > $maxRows, PdoRows::elapsed($started));
    }

    /**
     * @return list<list<string>>
     */
    private static function infoRows(string $info): array
    {
        $rows = [];
        $section = '';
        foreach (preg_split('/\r?\n/', $info) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }
            if (str_starts_with($line, '#')) {
                $section = trim(substr($line, 1));
            } elseif (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $rows[] = [$section, $key, $value];
            }
        }

        return $rows;
    }

    private static function cell(mixed $value): bool|float|int|string|null
    {
        if (\is_array($value)) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[unsupported]';
        }

        return CellValue::normalize($value);
    }
}
