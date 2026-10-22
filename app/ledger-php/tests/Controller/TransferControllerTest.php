<?php

namespace App\Tests\Controller;

use App\Service\TransferService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TransferControllerTest extends WebTestCase
{
    public function testTransferControllerCallsServiceCorrectly(): void
    {
        $client = static::createClient();

        // Setup mock service with array return type
        $expectedTxRef = 'TX-12345678';
        $serviceResponse = [
            'status' => 'SUCCESS',
            'transactionReference' => $expectedTxRef,
            'idempotencyKey' => 'test-key-abc'
        ];

        $transferService = $this->createMock(TransferService::class);
        $transferService->expects($this->once())
            ->method('executeTransfer')
            ->with(
                'test-key-abc',
                'acc-001',
                'acc-002',
                '250.00',
                'NZD',
                'Test Transfer'
            )
            ->willReturn($serviceResponse);

        // Override the service in the container
        static::getContainer()->set(TransferService::class, $transferService);

        // Make POST request
        $client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'HTTP_IDEMPOTENCY_KEY' => 'test-key-abc',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'sourceAccountId' => 'acc-001',
                'destinationAccountId' => 'acc-002',
                'amount' => '250.00',
                'currency' => 'NZD',
                'description' => 'Test Transfer',
            ])
        );

        $response = $client->getResponse();

        // Then
        $this->assertEquals(201, $response->getStatusCode());

        $responseData = json_decode($response->getContent(), true);
        $this->assertNotNull($responseData);
        $this->assertEquals('SUCCESS', $responseData['status']);
        $this->assertEquals($expectedTxRef, $responseData['transactionReference']);
        $this->assertEquals('test-key-abc', $responseData['idempotencyKey']);
    }
}
