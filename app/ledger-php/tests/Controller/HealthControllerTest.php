<?php

namespace App\Tests\Controller;

use App\Controller\HealthController;
use PHPUnit\Framework\TestCase;

class HealthControllerTest extends TestCase
{
    public function testHealthCheckReturnsUpStatus(): void
    {
        $controller = new HealthController();
        $response = $controller();

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertEquals('UP', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);
    }
}
