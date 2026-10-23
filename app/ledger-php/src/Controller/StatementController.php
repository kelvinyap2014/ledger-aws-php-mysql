<?php

namespace App\Controller;

use App\Service\StatementService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/accounts')]
class StatementController
{
    public function __construct(private StatementService $statementService) {}

    #[Route('/{id}/statement/url', name: 'api_account_statement_url', methods: ['GET'])]
    public function getStatementUrl(string $id): Response
    {
        $presignedUrl = $this->statementService->generateOrGetStatementPresignedUrl($id);

        $data = [
            'accountId' => $id,
            'statementUrl' => $presignedUrl,
            'expiresInMinutes' => '15'
        ];

        // Manually encode JSON with NO escaping for slashes and Unicode
        $jsonContent = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response($jsonContent, 200, ['Content-Type' => 'application/json']);
    }
}
