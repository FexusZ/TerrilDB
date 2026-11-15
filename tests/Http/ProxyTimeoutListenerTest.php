<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Json\RequestTimeLimit;
use App\Http\ProxyTimeoutListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Le proxy dit sa durée réelle dans un en-tête : si elle diffère de TERRILDB_PROXY_TIMEOUT, le journal le dit (une fois par heure). */
final class ProxyTimeoutListenerTest extends TestCase
{
    /**
     * @return AbstractLogger&object{records: list<array{string, string}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [\is_string($level) ? $level : '', (string) $message];
            }
        };
    }

    private function event(?string $header, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create('/api/health');
        if (null !== $header) {
            $request->headers->set('X-TerrilDB-Proxy-Timeout', $header);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    public function testAProxyThatAgreesWithTheConfigurationSaysNothing(): void
    {
        $logger = $this->logger();
        $listener = new ProxyTimeoutListener(new RequestTimeLimit(120), $logger, new ArrayAdapter());

        $listener($this->event('120'));

        self::assertSame([], $logger->records);
    }

    public function testAProxyThatDisagreesIsReportedWithBothValues(): void
    {
        $logger = $this->logger();
        $listener = new ProxyTimeoutListener(new RequestTimeLimit(120), $logger, new ArrayAdapter());

        $listener($this->event('60'));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertStringContainsString('60', $logger->records[0][1]);
        self::assertStringContainsString('120', $logger->records[0][1]);
        self::assertStringContainsString('sync-nginx.sh', $logger->records[0][1], 'The message says how to fix it.');
    }

    public function testTheSameDisagreementIsNotReportedOnEveryRequest(): void
    {
        $logger = $this->logger();
        $listener = new ProxyTimeoutListener(new RequestTimeLimit(120), $logger, new ArrayAdapter());

        $listener($this->event('60'));
        $listener($this->event('60'));
        $listener($this->event('60'));

        self::assertCount(1, $logger->records);
    }

    public function testDifferentValuesCannotBypassTheReminderNorFillTheCache(): void
    {
        $logger = $this->logger();
        $cache = new ArrayAdapter();
        $listener = new ProxyTimeoutListener(new RequestTimeLimit(120), $logger, $cache);

        foreach (['60', '61', '62', '999999'] as $value) {
            $listener($this->event($value));
        }

        self::assertCount(1, $logger->records, 'One reminder per hour, whatever the value announced.');
        self::assertCount(1, $cache->getValues());
    }

    public function testNoHeaderMeansNoProxyToCheck(): void
    {
        $logger = $this->logger();
        $listener = new ProxyTimeoutListener(new RequestTimeLimit(120), $logger, new ArrayAdapter());

        $listener($this->event(null));

        self::assertSame([], $logger->records);
    }

    public function testAnUnreadableHeaderIsIgnored(): void
    {
        $logger = $this->logger();
        $listener = new ProxyTimeoutListener(new RequestTimeLimit(120), $logger, new ArrayAdapter());

        $listener($this->event('abc'));
        $listener($this->event('-5'));

        self::assertSame([], $logger->records);
    }

    public function testSubRequestsAreIgnored(): void
    {
        $logger = $this->logger();
        $listener = new ProxyTimeoutListener(new RequestTimeLimit(120), $logger, new ArrayAdapter());

        $listener($this->event('60', HttpKernelInterface::SUB_REQUEST));

        self::assertSame([], $logger->records);
    }
}
