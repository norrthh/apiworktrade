<?php

namespace Tests\Feature;

use App\Services\Work1\CabinetClient;
use Tests\TestCase;

class CabinetApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['work1.api_token' => 'test-token']);
    }

    public function test_cabinet_routes_reject_a_missing_token(): void
    {
        $this->getJson('/api/cabinet/requisites')->assertUnauthorized();
        $this->postJson('/api/cabinet/requisites', [
            'values' => [
                'number' => '+79887034414',
                'recipient' => 'Andrei',
                'method' => 'sim',
                'bank_id' => '98',
            ],
        ])->assertUnauthorized();
    }

    public function test_list_returns_the_client_payload(): void
    {
        $this->mock(CabinetClient::class, function ($mock): void {
            $mock->shouldReceive('listCards')->once()->andReturn([
                'deleted' => false,
                'requisites' => [['id' => 7926, 'active' => true]],
            ]);
        });

        $this->withToken('test-token')
            ->getJson('/api/cabinet/requisites?archive_status=active')
            ->assertOk()
            ->assertJsonPath('requisites.0.id', 7926);
    }

    public function test_create_requires_the_trader_fields(): void
    {
        $this->withToken('test-token')
            ->postJson('/api/cabinet/requisites', ['values' => ['number' => '+79887034414']])
            ->assertStatus(422);
    }

    public function test_disable_returns_the_client_payload(): void
    {
        $this->mock(CabinetClient::class, function ($mock): void {
            $mock->shouldReceive('disableCard')->once()->with(7926)->andReturn([
                'requisite_id' => 7926,
                'active' => false,
            ]);
        });

        $this->withToken('test-token')
            ->postJson('/api/cabinet/requisites/7926/disable')
            ->assertOk()
            ->assertJsonPath('active', false);
    }

    public function test_bulk_requires_ids_and_active(): void
    {
        $this->withToken('test-token')
            ->postJson('/api/cabinet/requisites/bulk', ['ids' => [7926]])
            ->assertStatus(422);
    }
}
