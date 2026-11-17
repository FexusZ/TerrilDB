<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditAction;
use PHPUnit\Framework\TestCase;

final class AuditActionAdministrationTest extends TestCase
{
    public function testOnlyActionsDoneByAnAdministratorOnSomeoneElsesAccountBelongToTheJournal(): void
    {
        $journal = array_map(
            static fn (AuditAction $action): string => $action->value,
            array_values(array_filter(AuditAction::cases(), static fn (AuditAction $action): bool => $action->isAdministration())),
        );

        self::assertSame(['account.disabled', 'account.enabled', 'admin.token_revoked', 'admin.administrator_token_revoked'], $journal);
        self::assertSame('admin', AuditAction::AdminTokenRevoked->category());
        self::assertSame('admin', AuditAction::AdminAdministratorTokenRevoked->category());
    }
}
