<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Entity\User;
use App\Security\AccountAdministration;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Comptes de l'installation (administrateurs seulement). Rien de chiffré n'est jamais montré : ni connexions ni jetons.
 */
final readonly class AccountsController
{
    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private AccountAdministration $accounts,
        private WebSession $session,
        private AuditLog $audit,
    ) {
    }

    #[Route('/web/accounts', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $admin = $this->session->user();

        return new JsonResponse(['accounts' => array_map(
            static fn (User $account): array => AccountPresenter::present($account, $admin),
            $this->accounts->list($admin),
        )]);
    }

    #[Route('/web/accounts/{id}/disable', requirements: ['id' => self::ID], methods: ['POST'])]
    public function disable(string $id): JsonResponse
    {
        $admin = $this->session->user();
        $uuid = Uuid::fromString($id);
        if ($this->accounts->disable($admin, $uuid)) {
            $this->audit->record($this->session->auditContext($admin), AuditAction::AccountDisabled, subject: $uuid);
        }

        return new JsonResponse(['account' => AccountPresenter::present($this->accounts->find($uuid), $admin)]);
    }

    #[Route('/web/accounts/{id}/enable', requirements: ['id' => self::ID], methods: ['POST'])]
    public function enable(string $id): JsonResponse
    {
        $admin = $this->session->user();
        $uuid = Uuid::fromString($id);
        if ($this->accounts->enable($admin, $uuid)) {
            $this->audit->record($this->session->auditContext($admin), AuditAction::AccountEnabled, subject: $uuid);
        }

        return new JsonResponse(['account' => AccountPresenter::present($this->accounts->find($uuid), $admin)]);
    }
}
