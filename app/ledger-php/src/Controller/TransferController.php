<?php

namespace App\Controller;

use App\Service\TransferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class TransferController extends AbstractController
{
    public function __construct(private TransferService $transferService) {}

    #[Route('/transfers', name: 'api_execute_transfer', methods: ['POST'])]
    public function transfer(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        try {
            $txRef = $this->transferService->executeTransfer(
                $idempotencyKey,
                $data['sourceAccountId'] ?? '',
                $data['destinationAccountId'] ?? '',
                $data['amount'] ?? '0.0000',
                $data['currency'] ?? 'NZD',
                $data['description'] ?? ''
            );

            return $this->json([
                'status' => 'SUCCESS',
                'transactionReference' => $txRef,
                'idempotencyKey' => $idempotencyKey
            ], 201);

        } catch (\Exception $e) {
            return $this->json([
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'message' => $e->getMessage(),
                'error' => 'Bad Request',
                'status' => 400
            ], 400);
        }
    }
}
