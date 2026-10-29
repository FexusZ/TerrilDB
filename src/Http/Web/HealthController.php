<?php

declare(strict_types=1);

namespace App\Http\Web;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'app' => 'TerrilDB']);
    }
}
