<?php

declare(strict_types=1);

// Sonde de test : joue le CLIENT (la requête web) dans un autre processus et écrit « ok », « busy » ou le nom de l'exception.
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Query\WritePermissions;
use App\ScriptRun\Channel\ScriptRunChannel;
use App\ScriptRun\Channel\ScriptRunDispatcher;
use App\ScriptRun\ScriptRunRequest;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
assert(is_array($arguments));
$socket = is_string($arguments[1] ?? null) ? $arguments[1] : '';
$secret = is_string($arguments[2] ?? null) ? $arguments[2] : '';
$request = new ScriptRunRequest(Uuid::v7(), Engine::PostgreSql, new ConnectionParameters('db.example.test', 5432, 'shop', 'app', 'pw-unique-2'), 'SELECT 1', new WritePermissions(), false, false, 10_000, new AuditContext(AuditActor::Web, null, null, null));
try {
    (new ScriptRunDispatcher($socket, new ScriptRunChannel($secret)))->dispatch($request);
    echo 'ok';
} catch (Throwable $e) {
    echo (new ReflectionClass($e))->getShortName();
}
