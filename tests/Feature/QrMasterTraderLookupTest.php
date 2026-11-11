<?php

namespace Tests\Feature;

use App\Services\Work1\Work1Response;
use App\Services\Work1\Work1Transport;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class QrMasterTraderLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['work1.qrmaster_test_enabled' => true, 'work1.qrmaster_test_token' => 'lookup-token']);
    }

    public function test_requires_its_private_token(): void
    {
        $this->getJson('/api/qrmaster/test/traders?id=1')->assertForbidden();
        config(['work1.qrmaster_test_enabled' => false]);
        $this->withToken('lookup-token')->getJson('/api/qrmaster/test/traders?id=1')->assertForbidden();
    }

    public function test_returns_only_identity_and_status_without_changing_the_platform(): void
    {
        $transport = Mockery::mock(Work1Transport::class);
        $transport->shouldReceive('get')->once()->with('/admin/site/update?id=28')->andReturn(new Work1Response(200,
            '<form action="/admin/site/update?id=28"><input name="UserUpdateForm[fio]" value="Test Trader"><input name="UserUpdateForm[email]" value="test@example.test"><input name="UserUpdateForm[status]" value="10"><input name="secret" value="never-expose"></form>'));
        $transport->shouldNotReceive('post');
        $this->app->instance(Work1Transport::class, $transport);
        $this->withToken('lookup-token')->getJson('/api/qrmaster/test/traders?id=28')->assertOk()
            ->assertExactJson(['id' => 28, 'name' => 'Test Trader', 'email' => 'test@example.test', 'active' => true]);
    }

    public function test_rejects_nonexistent_and_wrong_identity_pages(): void
    {
        $transport = Mockery::mock(Work1Transport::class);
        $transport->shouldReceive('get')->once()->with('/admin/site/update?id=28')->andReturn(new Work1Response(404, 'Not found'));
        $transport->shouldReceive('get')->once()->with('/admin/site/update?id=29')->andReturn(new Work1Response(200,
            '<form action="/admin/site/update?id=99"><input name="UserUpdateForm[fio]" value="Another"><input name="UserUpdateForm[status]" value="10"></form>'));
        $this->app->instance(Work1Transport::class, $transport);
        $this->withToken('lookup-token')->getJson('/api/qrmaster/test/traders?id=28')->assertNotFound();
        $this->getJson('/api/qrmaster/test/traders?id=29')->assertStatus(503);
    }

    public function test_rejects_invalid_ids_and_does_not_interrupt_an_assignment(): void
    {
        $this->withToken('lookup-token')->getJson('/api/qrmaster/test/traders?id=-1')->assertUnprocessable();
        $lock = Cache::lock('qrmaster:trader-session', 180);
        $lock->get();
        $this->getJson('/api/qrmaster/test/traders?id=1')->assertStatus(503);
        $lock->release();
    }
}
