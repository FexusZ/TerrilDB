<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    // Secret de test de la RFC 6238 : "12345678901234567890" encodé en base32.
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testGeneratedSecretsAreBase32AndUnique(): void
    {
        $service = new TotpService();

        $first = $service->generateSecret();
        $second = $service->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function testProvisioningUriDescribesTheAuthenticatorEntry(): void
    {
        $uri = (new TotpService())->provisioningUri(self::RFC_SECRET, 'alice');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('TerrilDB', $uri);
        self::assertStringContainsString('alice', $uri);
        self::assertStringContainsString('secret='.self::RFC_SECRET, $uri);
        self::assertStringContainsString('issuer=TerrilDB', $uri);
        // SHA-1, 6 chiffres et 30 s sont les valeurs par défaut des applications : elles ne sont pas répétées.
        self::assertStringNotContainsString('algorithm=', $uri);
    }

    /**
     * Vecteurs de la RFC 6238 (SHA-1), tronqués à 6 chiffres.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function rfcVectors(): iterable
    {
        yield 'T=59' => [59, '287082'];
        yield 'T=1111111109 (zéro initial)' => [1111111109, '081804'];
        yield 'T=1111111111' => [1111111111, '050471'];
        yield 'T=1234567890 (zéros initiaux)' => [1234567890, '005924'];
        yield 'T=2000000000' => [2000000000, '279037'];
    }

    #[DataProvider('rfcVectors')]
    public function testAcceptsTheRfcVectorsAndReturnsTheirTimeStep(int $timestamp, string $code): void
    {
        $step = (new TotpService())->verify(self::RFC_SECRET, $code, 0, $timestamp);

        self::assertSame(intdiv($timestamp, 30), $step);
    }

    public function testToleratesOneStepOfClockDriftEitherWay(): void
    {
        $service = new TotpService();

        // Le code est celui de la période 1 (T=30..59).
        self::assertSame(1, $service->verify(self::RFC_SECRET, '287082', 0, 89), 'horloge du client en retard');
        self::assertSame(1, $service->verify(self::RFC_SECRET, '287082', 0, 29), 'horloge du client en avance');
    }

    public function testRejectsACodeTwoStepsAway(): void
    {
        self::assertNull((new TotpService())->verify(self::RFC_SECRET, '287082', 0, 59 + 60));
    }

    public function testReplayOfAnAlreadyUsedStepIsRejected(): void
    {
        $service = new TotpService();

        self::assertNull($service->verify(self::RFC_SECRET, '287082', 1, 59));
    }

    public function testALaterStepIsStillAcceptedAfterAnEarlierOne(): void
    {
        $service = new TotpService();
        $laterCode = '081804'; // pas 37037036

        self::assertSame(37037036, $service->verify(self::RFC_SECRET, $laterCode, 1, 1111111109));
    }

    public function testRejectsAWrongCode(): void
    {
        self::assertNull((new TotpService())->verify(self::RFC_SECRET, '000000', 0, 59));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCodes(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['28708'];
        yield 'trop long' => ['2870822'];
        yield 'lettres' => ['28708a'];
        yield 'signe' => ['-287082'];
    }

    #[DataProvider('malformedCodes')]
    public function testRejectsMalformedCodes(string $code): void
    {
        self::assertNull((new TotpService())->verify(self::RFC_SECRET, $code, 0, 59));
    }

    public function testSpacesTypedByTheUserAreIgnored(): void
    {
        self::assertSame(1, (new TotpService())->verify(self::RFC_SECRET, ' 287 082 ', 0, 59));
    }
}
