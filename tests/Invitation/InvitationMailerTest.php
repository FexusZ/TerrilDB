<?php

declare(strict_types=1);

namespace App\Tests\Invitation;

use App\Entity\Exception\InvalidInvitationException;
use App\Invitation\Exception\MailDisabledException;
use App\Invitation\Exception\MailNotSentException;
use App\Invitation\InvitationMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class InvitationMailerTest extends TestCase
{
    private const TOKEN = 'inv_0123456789abcdef0123456789abcdef_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private function mailer(?\Throwable $failure = null): RecordingMailer
    {
        return new RecordingMailer($failure);
    }

    private function invitationMailer(MailerInterface $transport, string $from = 'terrildb@example.test', string $url = 'https://db.example.test/'): InvitationMailer
    {
        return new InvitationMailer($transport, $from, $url);
    }

    public function testIsEnabledOnlyWithASenderAndAPublicAddress(): void
    {
        $transport = $this->mailer();

        self::assertTrue($this->invitationMailer($transport)->isEnabled());
        self::assertFalse($this->invitationMailer($transport, from: '')->isEnabled());
        self::assertFalse($this->invitationMailer($transport, url: '')->isEnabled());
    }

    public function testBuildsTheRegistrationLinkFromTheConfiguredAddress(): void
    {
        $transport = $this->mailer();

        self::assertSame('https://db.example.test/app/register?invitation='.self::TOKEN, $this->invitationMailer($transport)->link(self::TOKEN));
        self::assertSame('http://localhost:8000/app/register?invitation='.self::TOKEN, $this->invitationMailer($transport, url: 'http://localhost:8000')->link(self::TOKEN));
    }

    public function testSendsAPlainTextMailWithTheLinkTheInviterAndTheExpiry(): void
    {
        $transport = $this->mailer();

        $mailer = $this->invitationMailer($transport);
        $mailer->send($mailer->recipient('marie@example.test'), self::TOKEN, new \DateTimeImmutable('2026-11-08T10:00:00+00:00'), 'alice');

        self::assertCount(1, $transport->sent);
        $mail = $transport->sent[0];
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('marie@example.test', $mail->getTo()[0]->getAddress());
        self::assertSame('terrildb@example.test', $mail->getFrom()[0]->getAddress());
        self::assertStringNotContainsString('inv_', (string) $mail->getSubject());
        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('https://db.example.test/app/register?invitation='.self::TOKEN, $text);
        self::assertStringContainsString('alice', $text);
        self::assertStringContainsString('08/11/2026', $text);
        self::assertNull($mail->getHtmlBody());
    }

    public function testRefusesToSendWhenNotConfigured(): void
    {
        $transport = $this->mailer();

        $this->expectException(MailDisabledException::class);

        $this->invitationMailer($transport, from: '')->recipient('marie@example.test');
    }

    public function testRefusesAnInvalidAddress(): void
    {
        $transport = $this->mailer();

        $this->expectException(InvalidInvitationException::class);

        $this->invitationMailer($transport)->recipient('not an address');
    }

    public function testAFailingTransportNeverLeaksTheAddressOrTheToken(): void
    {
        $transport = $this->mailer(new TransportException('Connection to smtp.secret.example refused for marie@example.test'));

        try {
            $mailer = $this->invitationMailer($transport);
            $mailer->send($mailer->recipient('marie@example.test'), self::TOKEN, new \DateTimeImmutable('2026-11-08T10:00:00+00:00'), 'alice');
            self::fail('The failure must surface.');
        } catch (MailNotSentException $e) {
            self::assertStringNotContainsString('marie@', $e->getMessage());
            self::assertStringNotContainsString('inv_', $e->getMessage());
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }
}
