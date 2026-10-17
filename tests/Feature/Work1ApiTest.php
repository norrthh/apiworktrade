<?php

namespace Tests\Feature;

use App\Services\Work1\MfaRequiredException;
use App\Services\Work1\Work1Client;
use Tests\TestCase;

class Work1ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['work1.api_token' => 'test-token']);
    }

    public function test_offer_endpoint_rejects_a_missing_token(): void
    {
        $this->postJson('/api/offers', [
            'merchant_code' => 'M_QHGRDP5U',
            'merchant_name' => 'Shop',
            'method' => 'humo',
        ])->assertUnauthorized();
    }

    public function test_create_offer_returns_the_client_payload(): void
    {
        $this->mock(Work1Client::class, function ($mock) {
            $mock->shouldReceive('createOffer')->once()->with('M_QHGRDP5U', 'Shop', 'humo')->andReturn([
                'offer_id' => 15,
                'merchant_code' => 'M_QHGRDP5U',
                'method' => 'humo',
                'agent' => null,
                'created' => true,
            ]);
        });

        $this->withToken('test-token')->postJson('/api/offers', [
            'merchant_code' => 'M_QHGRDP5U',
            'merchant_name' => 'Shop',
            'method' => 'humo',
        ])->assertOk()->assertJsonPath('offer_id', 15);
    }

    public function test_create_offer_accepts_a_method_slug_from_the_cabinet_form(): void
    {
        $this->mock(Work1Client::class, function ($mock) {
            $mock->shouldReceive('createOffer')->once()->with('M_3RRVXQT1', 'EHOT', 'm2alfa')->andReturn([
                'offer_id' => 500,
                'merchant_code' => 'M_3RRVXQT1',
                'method' => 'm2alfa',
                'agent' => null,
                'created' => true,
            ]);
        });

        $this->withToken('test-token')->postJson('/api/offers', [
            'merchant_code' => 'M_3RRVXQT1',
            'merchant_name' => 'EHOT',
            'method' => 'm2alfa',
        ])->assertOk()->assertJsonPath('method', 'm2alfa');
    }

    public function test_create_offer_rejects_a_method_that_is_not_a_slug(): void
    {
        $this->withToken('test-token')->postJson('/api/offers', [
            'merchant_code' => 'M_3RRVXQT1',
            'merchant_name' => 'EHOT',
            'method' => 'Alfa Bank',
        ])->assertStatus(422);
    }

    public function test_session_reports_mfa(): void
    {
        $this->mock(Work1Client::class, function ($mock) {
            $mock->shouldReceive('ensure')->once()->andThrow(new MfaRequiredException);
        });

        $this->withToken('test-token')
            ->getJson('/api/session')
            ->assertStatus(428)
            ->assertJson(['mfa_required' => true]);
    }

    public function test_bank_and_requisite_writes_require_a_token_and_values(): void
    {
        $this->postJson('/api/banks', ['values' => ['name' => 'Ipak']])->assertUnauthorized();
        $this->putJson('/api/banks/9', ['values' => ['name' => 'Ipak']])->assertUnauthorized();
        $this->postJson('/api/requisites', ['values' => ['number' => '1']])->assertUnauthorized();

        $this->withToken('test-token')->postJson('/api/banks', [])->assertStatus(422);
        $this->withToken('test-token')->putJson('/api/requisites/5', [
            'values' => ['password' => 'secret'],
        ])->assertStatus(422);
    }

    public function test_create_bank_returns_the_client_payload(): void
    {
        $this->mock(Work1Client::class, function ($mock) {
            $mock->shouldReceive('createBank')->once()->with(['name' => 'Ipak Yuli'])->andReturn([
                'bank_id' => 9,
                'fields' => ['BankForm[name]' => 'Ipak Yuli'],
            ]);
        });

        $this->withToken('test-token')->postJson('/api/banks', [
            'values' => ['name' => 'Ipak Yuli'],
        ])->assertOk()->assertJsonPath('bank_id', 9);
    }

    public function test_create_method_requires_a_name_a_type_and_banks(): void
    {
        $this->postJson('/api/methods', [
            'values' => ['name' => 'm2nozon', 'method_type' => 'tel', 'bank_ids' => '51'],
        ])->assertUnauthorized();

        $this->withToken('test-token')->postJson('/api/methods', [
            'values' => ['name' => 'm2nozon'],
        ])->assertStatus(422);

        $this->mock(Work1Client::class, function ($mock) {
            $mock->shouldReceive('createMethod')->once()->with([
                'name' => 'm2nozon',
                'method_type' => 'tel',
                'bank_ids' => '51',
            ])->andReturn([
                'method_id' => 120,
                'name' => 'm2nozon',
                'created' => true,
            ]);
        });

        $this->withToken('test-token')->postJson('/api/methods', [
            'values' => ['name' => 'm2nozon', 'method_type' => 'tel', 'bank_ids' => '51'],
        ])->assertCreated()->assertJsonPath('method_id', 120);
    }
}
