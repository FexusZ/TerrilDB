<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Joue le VRAI docker/prod/deploy.sh contre un faux `docker` (script du PATH qui consigne ses appels) et un dossier de
 * configuration jetable : jamais de vrai déploiement.
 */
final class DeployScriptSafetyTest extends TestCase
{
    private string $work;
    private string $dir;
    private string $bin;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir().'/deploy-safety-'.bin2hex(random_bytes(6));
        $this->dir = $this->work.'/config';
        $this->bin = $this->work.'/bin';
        mkdir($this->bin, 0700, true);
        mkdir($this->dir.'/backups', 0700, true);

        file_put_contents($this->bin.'/docker', <<<'SH'
            #!/bin/sh
            echo "docker $*" >> "$FAKE_LOG"
            case "$*" in
                *"pg_dump"*)
                    if [ "${FAKE_DUMP_FAIL:-}" = 1 ]; then echo "-- debut partiel"; echo "pg_dump: erreur" >&2; exit 1; fi
                    echo "-- dump complet"
                    ;;
                *"doctrine:migrations:migrate"*) echo MIGRATE >> "$FAKE_LOG" ;;
            esac
            exit 0
            SH);
        chmod($this->bin.'/docker', 0700);
        file_put_contents($this->dir.'/db.env', "POSTGRES_DB=x\n");
        file_put_contents($this->dir.'/app.env', "APP_SECRET=x\n");
        file_put_contents($this->dir.'/current-tag', "oldtag\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->work);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function deploy(array $env = []): array
    {
        $process = new Process(['sh', \dirname(__DIR__, 2).'/docker/prod/deploy.sh'], null, $env + [
            'PATH' => $this->bin.':'.getenv('PATH'),
            'TERRILDB_DEPLOY_DIR' => $this->dir,
            'TERRILDB_NGINX_SITE' => $this->work.'/absent',
            'FAKE_LOG' => $this->work.'/log',
        ]);
        $process->run();
        $log = @file_get_contents($this->work.'/log');

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput(), \is_string($log) ? $log : ''];
    }

    /**
     * @return list<string>
     */
    private function backups(): array
    {
        return array_map('basename', glob($this->dir.'/backups/*') ?: []);
    }

    public function testAFailingDumpStopsBeforeTheMigrationAndLeavesNoPartialBackup(): void
    {
        file_put_contents($this->dir.'/deploy.env', "TERRILDB_DOMAIN=exemple.test\n");
        $old = $this->dir.'/backups/20200101-000000-aaa.sql.gz';
        file_put_contents($old, 'valide');
        foreach (range(1, 8) as $i) {
            touch($this->dir."/backups/2020010$i-old$i.sql.gz", 1_000_000 + $i);
        }
        $before = $this->backups();

        [$code, $output, $log] = $this->deploy(['FAKE_DUMP_FAIL' => '1']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('sauvegarde', $output);
        self::assertStringNotContainsString('MIGRATE', $log);
        self::assertSame($before, $this->backups(), 'Aucun partiel, aucune purge des sauvegardes valides.');
    }

    public function testASuccessfulDumpIsGzippedPrivateAndTheMigrationRuns(): void
    {
        file_put_contents($this->dir.'/deploy.env', "TERRILDB_DOMAIN=exemple.test\n");

        [$code, $output, $log] = $this->deploy();

        self::assertSame(0, $code);
        self::assertStringContainsString('MIGRATE', $log);
        self::assertMatchesRegularExpression('/Sauvegarde de la base : \S+\.sql\.gz \(\d+ octets\)/', $output, 'Le succès de la sauvegarde est dit.');
        $backups = glob($this->dir.'/backups/*.sql.gz') ?: [];
        self::assertCount(1, $backups);
        self::assertSame('0600', substr(\sprintf('%o', fileperms($backups[0])), -4));
        self::assertStringContainsString('dump complet', (string) shell_exec('gzip -dc '.escapeshellarg($backups[0])));
        self::assertSame([basename($backups[0])], $this->backups(), 'Aucun fichier temporaire ne reste.');
    }

    public function testTheBackupIsCreatedPrivateFromTheStart(): void
    {
        self::assertMatchesRegularExpression('/^\s*umask 077/m', (string) file_get_contents(\dirname(__DIR__, 2).'/docker/prod/deploy.sh'));
    }

    public function testRedeployingKeepsEveryExistingSettingAndOnlyUpdatesTheDomain(): void
    {
        file_put_contents($this->dir.'/deploy.env', "TERRILDB_DOMAIN=ancien.test\nTERRILDB_PORT=9000\nTERRILDB_BIND=10.0.0.5\nTERRILDB_PROXY_TIMEOUT=300\nAUTRE=\"a b\"\n");
        chmod($this->dir.'/deploy.env', 0640);

        [$code] = $this->deploy(['TERRILDB_DOMAIN' => 'nouveau.test']);

        self::assertSame(0, $code);
        $lines = file($this->dir.'/deploy.env', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        sort($lines);
        self::assertSame(['AUTRE="a b"', 'TERRILDB_BIND=10.0.0.5', 'TERRILDB_DOMAIN=nouveau.test', 'TERRILDB_PORT=9000', 'TERRILDB_PROXY_TIMEOUT=300'], $lines);
        clearstatcache();
        self::assertSame('0640', substr(\sprintf('%o', fileperms($this->dir.'/deploy.env')), -4));
        self::assertSame([], glob($this->dir.'/.deploy.env.*') ?: [], 'Pas de fichier temporaire oublié.');
    }

    public function testAFirstDeploymentWritesTheDefaults(): void
    {
        unlink($this->dir.'/current-tag');

        [$code] = $this->deploy(['TERRILDB_DOMAIN' => 'exemple.test']);

        self::assertSame(0, $code);
        self::assertSame(
            "TERRILDB_DOMAIN=exemple.test\nTERRILDB_PORT=8090\nTERRILDB_BIND=127.0.0.1\nTERRILDB_PROXY_TIMEOUT=120\n",
            file_get_contents($this->dir.'/deploy.env'),
        );
        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->dir.'/deploy.env')), -4));
    }

    public function testADomainThatCouldInjectAnotherLineIsRefused(): void
    {
        [$code] = $this->deploy(['TERRILDB_DOMAIN' => "a.test\nTERRILDB_PORT=1"]);

        self::assertSame(2, $code);
        self::assertFileDoesNotExist($this->dir.'/deploy.env');
    }
}
