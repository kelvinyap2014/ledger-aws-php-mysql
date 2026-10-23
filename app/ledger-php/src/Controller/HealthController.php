<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/health', name: 'api_health_check', methods: ['GET'])]
class HealthController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'UP',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ], 200);
    }
}
