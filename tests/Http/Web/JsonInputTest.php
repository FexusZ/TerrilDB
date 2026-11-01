<?php

declare(strict_types=1);

namespace App\Tests\Http\Web;

use App\Http\Web\BadRequestException;
use App\Http\Web\JsonInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class JsonInputTest extends TestCase
{
    private function input(string $body): JsonInput
    {
        return JsonInput::fromRequest(Request::create('/x', 'POST', content: $body, server: ['CONTENT_TYPE' => 'application/json']));
    }

    public function testReadsTypedValues(): void
    {
        $input = $this->input('{"s":"text","i":5,"b":true,"n":null}');

        self::assertSame('text', $input->string('s'));
        self::assertSame(5, $input->int('i'));
        self::assertTrue($input->bool('b'));
        self::assertNull($input->optionalString('n'));
        self::assertNull($input->optionalString('absent'));
        self::assertFalse($input->bool('absent', false));
        self::assertSame(7, $input->optionalInt('absent', 7));
    }

    public function testAnEmptyBodyIsAnEmptyObject(): void
    {
        self::assertNull($this->input('')->optionalString('x'));
    }

    public function testRefusesMalformedJson(): void
    {
        $this->expectException(BadRequestException::class);

        $this->input('{nope');
    }

    public function testRefusesANonObjectBody(): void
    {
        $this->expectException(BadRequestException::class);

        $this->input('"text"');
    }

    public function testRefusesAMissingOrWronglyTypedRequiredValue(): void
    {
        $input = $this->input('{"s":5,"i":"5","b":"yes"}');

        foreach ([
            static fn () => $input->string('absent'),
            static fn () => $input->string('s'),
            static fn () => $input->int('i'),
            static fn () => $input->bool('b'),
            static fn () => $input->optionalString('s'),
            static fn () => $input->optionalInt('i'),
        ] as $read) {
            try {
                $read();
                self::fail('Expected a refusal.');
            } catch (BadRequestException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testErrorMessagesNameTheFieldNotTheValue(): void
    {
        try {
            $this->input('{"password":5}')->string('password');
            self::fail('Expected a refusal.');
        } catch (BadRequestException $e) {
            self::assertStringContainsString('password', $e->getMessage());
            self::assertStringNotContainsString('5', $e->getMessage());
        }
    }
}
