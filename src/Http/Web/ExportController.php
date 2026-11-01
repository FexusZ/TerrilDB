<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedQueries;
use App\Core\Driver\TargetConnection;
use App\Core\Export\CsvExporter;
use App\Core\Export\JsonExporter;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Http\Json\BadRequestException;
use App\Http\Json\JsonInput;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Exporte le résultat d'une requête en fichier. Toujours en lecture seule, avec les mêmes garde-fous et le même audit
 * qu'une exécution ordinaire ; la limite de lignes est plus haute (10 000 par défaut, 100 000 au plus).
 */
final readonly class ExportController
{
    private const DEFAULT_ROWS = 10_000;

    public function __construct(
        private TargetAccess $targets,
        private AuditedQueries $queries,
        private WebSession $session,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/web/connections/{id}/export', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function export(string $id, Request $request): Response
    {
        $input = JsonInput::fromRequest($request);
        $sql = $input->string('sql');
        $format = $input->string('format');
        if (!\in_array($format, ['csv', 'json'], true)) {
            throw new BadRequestException('The format must be "csv" or "json".');
        }
        $bom = $input->bool('bom', false);
        $options = new QueryOptions(QueryMode::ReadOnly, (new QueryOptions())->timeoutMs, $input->optionalInt('maxRows', self::DEFAULT_ROWS) ?? self::DEFAULT_ROWS);

        $uuid = Uuid::fromString($id);
        $result = $this->targets->withSaved(
            $uuid,
            fn (TargetConnection $target) => $this->queries->execute($this->session->auditContext(), $uuid, $target, $sql, $options, extra: ['purpose' => 'export', 'format' => $format]),
        );

        $body = 'csv' === $format ? (new CsvExporter())->export($result, $bom) : (new JsonExporter())->export($result);
        $filename = \sprintf('terrildb-export-%s.%s', $this->clock->now()->format('Ymd-His'), $format);
        $headers = [
            'Content-Type' => 'csv' === $format ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ];
        if ($result->truncated) {
            $headers['X-TerrilDB-Truncated'] = '1';
        }

        return new Response($body, Response::HTTP_OK, $headers);
    }
}
