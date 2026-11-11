<?php

namespace Tests\Feature;

use App\Services\Work1\Work1Exception;
use App\Services\Work1\Work1Response;
use App\Services\Work1\Work1Transport;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QrMasterTestCompletionTest extends TestCase
{
    private FakeQrMasterTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['work1.qrmaster_test_enabled' => true, 'work1.qrmaster_test_token' => 'test-bridge-token',
            'work1.base_url' => 'https://work.pipay.su', 'work1.totp_secret' => 'JBSWY3DPEHPK3PXP']);
        Cache::flush();
        $this->transport = new FakeQrMasterTransport;
        $this->app->instance(Work1Transport::class, $this->transport);
    }

    private function payload(string $action = 'claim', bool $reconcile = false): array
    {
        return ['payment_id' => 'ptest-payment', 'provider_id' => '0192b7e0-6a1c-7f3e-9a51-2c4d5e6f7a8b',
            'amount_rub' => '1500.00', 'trader_id' => 1, 'action' => $action, 'reconcile_only' => $reconcile];
    }

    public function test_live_claim_and_completion_require_live_token_mode_and_original_qr_hash(): void
    {
        $this->transport->live = true;
        config(['work1.qrmaster_live_enabled' => true, 'work1.qrmaster_live_token' => 'live-bridge-token']);
        $payload = $this->payload() + ['mode' => 'live', 'qr_sha256' => hash('sha256', 'https://qr.nspk.ru/live-qr?sum=150000')];
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/live/payout', $payload)->assertForbidden();
        $this->withToken('live-bridge-token')->postJson('/api/qrmaster/live/payout', array_replace($payload, ['mode' => 'test']))->assertUnprocessable();
        $this->postJson('/api/qrmaster/live/payout', array_replace($payload, ['qr_sha256' => null]))->assertUnprocessable();
        $this->postJson('/api/qrmaster/live/payout', array_replace($payload, ['qr_sha256' => str_repeat('a', 64)]))->assertUnprocessable();
        $this->assertSame(0, $this->transport->writes);
        $this->postJson('/api/qrmaster/live/payout', $payload)->assertOk()->assertJsonPath('result', 'assigned');
        $this->postJson('/api/qrmaster/live/payout', array_replace($payload, ['action' => 'complete']))->assertOk()->assertJsonPath('result', 'success');
        $this->postJson('/api/qrmaster/live/payout', array_replace($payload, ['action' => 'complete']))->assertOk()->assertJsonPath('result', 'success');
        $this->assertSame(2, $this->transport->writes);
        $this->assertStringContainsString('QRMaster live: paid verified', $this->transport->completionFields['DealForm[comment]']);
    }

    public function test_live_token_cannot_authorize_test_or_disabled_live_writes(): void
    {
        config(['work1.qrmaster_live_enabled' => false, 'work1.qrmaster_live_token' => 'live-bridge-token']);
        $this->withToken('live-bridge-token')->postJson('/api/qrmaster/live/payout', $this->payload())->assertForbidden();
        $this->postJson('/api/qrmaster/test/payout', $this->payload())->assertForbidden();
        $this->assertSame(0, $this->transport->writes);
    }

    public function test_requires_separate_token_and_enabled_flag(): void
    {
        $this->postJson('/api/qrmaster/test/payout', $this->payload())->assertForbidden();
        config(['work1.qrmaster_test_enabled' => false]);
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload())->assertForbidden();
        $this->assertSame(0, $this->transport->writes);
    }

    public function test_claims_as_linked_trader_and_completes_exactly_once(): void
    {
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload())->assertOk()->assertJsonPath('result', 'assigned');
        $this->assertSame('2', $this->transport->status);
        $this->assertSame(1, $this->transport->traderId);
        $this->postJson('/api/qrmaster/test/payout', $this->payload())->assertOk()->assertJsonPath('result', 'assigned');
        $this->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertOk()->assertJsonPath('result', 'success');
        $this->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertOk()->assertJsonPath('result', 'success');
        $this->assertSame(2, $this->transport->writes);
        $this->assertSame(['2', '5'], $this->transport->postedStatuses);
    }

    public function test_never_steals_another_traders_payout(): void
    {
        $this->transport->traderId = 28;
        $this->transport->status = '2';
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload())->assertOk()->assertJsonPath('result', 'assigned_elsewhere');
        $this->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertOk()->assertJsonPath('result', 'assigned_elsewhere');
        $this->assertSame(0, $this->transport->writes);
    }

    public function test_logs_trader_login_refusal_without_secrets(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });
        $this->transport->refuseLogin = true;
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('error', 'QRMASTER_TRADER_LOGIN_FAILED');
        $refusal = collect($logged)->first(fn (MessageLogged $event): bool => $event->message === '[QrMasterTestPayouts.loginAsTrader] refused');
        $this->assertNotNull($refusal);
        $encoded = json_encode($refusal->context, JSON_UNESCAPED_UNICODE);
        $this->assertSame('warning', $refusal->level);
        $this->assertSame(200, $refusal->context['http_status']);
        $this->assertSame('/admin/site/login-as', $refusal->context['location_path']);
        $this->assertSame(1, $refusal->context['trader_id']);
        $this->assertSame('Неверный код', $refusal->context['alert']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('mfa_code', $encoded);
    }

    public function test_reconciles_a_lost_response_without_repeating_the_write(): void
    {
        $this->transport->loseResponse = true;
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload())->assertUnprocessable();
        $this->postJson('/api/qrmaster/test/payout', $this->payload('claim', true))->assertOk()->assertJsonPath('result', 'assigned');
        $this->assertSame(1, $this->transport->writes);
    }

    public function test_completes_with_provider_confirmation_preserving_financial_fields_and_comment(): void
    {
        $this->transport->status = '2';
        $this->transport->traderId = 1;
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertOk()->assertJsonPath('result', 'success');
        $fields = $this->transport->completionFields;
        $this->assertSame('save_and_colback', $fields['save']);
        $this->assertSame('1500.0000', $fields['DealForm[amount]']);
        $this->assertSame('84.28', $fields['DealForm[rate]']);
        $this->assertSame('14.00', $fields['DealForm[commission]']);
        $this->assertSame('14768', $fields['DealForm[card_id]']);
        $this->assertSame('1', $fields['DealForm[user_id]']);
        $this->assertStringStartsWith("Existing note\nQRMaster test: paid verified;", $fields['DealForm[comment]']);
        $this->assertStringContainsString($this->payload()['provider_id'], $fields['DealForm[comment]']);
        $this->assertSame(302, Cache::get('qrmaster:complete:ptest-payment')['response_status']);
    }

    public function test_reconciles_completed_admin_action_after_a_lost_response(): void
    {
        $this->transport->status = '2';
        $this->transport->traderId = 1;
        $this->transport->loseResponse = true;
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertUnprocessable();
        $this->postJson('/api/qrmaster/test/payout', $this->payload('complete', true))->assertOk()->assertJsonPath('result', 'success');
        $this->assertSame(1, $this->transport->writes);
    }

    public function test_does_not_repeat_an_unconfirmed_admin_action(): void
    {
        $this->transport->status = '2';
        $this->transport->traderId = 1;
        $this->transport->ignoreWrite = true;
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertOk()->assertJsonPath('result', 'unknown');
        $this->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertOk()->assertJsonPath('result', 'unknown');
        $this->assertSame(1, $this->transport->writes);
    }

    public function test_rejects_missing_admin_callback_action(): void
    {
        $this->transport->status = '2';
        $this->transport->traderId = 1;
        $this->transport->mismatch = 'completion_action';
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertUnprocessable();
        $this->assertSame(0, $this->transport->writes);
    }

    public function test_never_retries_an_unresolved_write_even_if_the_caller_retries_with_false(): void
    {
        $this->transport->ignoreWrite = true;
        $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload())->assertOk()->assertJsonPath('result', 'unknown');
        $this->postJson('/api/qrmaster/test/payout', $this->payload())->assertOk()->assertJsonPath('result', 'unknown');
        $this->assertSame(1, $this->transport->writes);
    }

    public function test_rejects_another_provider_amount_merchant_method_or_session(): void
    {
        foreach (['provider', 'amount', 'merchant', 'method', 'session'] as $mismatch) {
            Cache::flush();
            $this->transport->mismatch = $mismatch;
            $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload())->assertUnprocessable();
            $this->assertSame(0, $this->transport->writes);
        }
    }

    public function test_does_not_reopen_terminal_or_disputed_payouts(): void
    {
        foreach (['3', '4', '6'] as $status) {
            $this->transport->status = $status;
            $this->transport->traderId = 1;
            $this->withToken('test-bridge-token')->postJson('/api/qrmaster/test/payout', $this->payload('complete'))->assertOk()->assertJsonPath('result', 'terminal');
        }
        $this->assertSame(0, $this->transport->writes);
    }
}

final class FakeQrMasterTransport implements Work1Transport
{
    public string $status = '1';

    public int $traderId = 0;

    public int $writes = 0;

    public bool $loseResponse = false;

    public bool $refuseLogin = false;

    public bool $ignoreWrite = false;

    public string $mismatch = '';

    public bool $live = false;

    public array $postedStatuses = [];

    public array $completionFields = [];

    public function ensure(): void {}

    public function submitOtp(string $otp): void {}

    public function get(string $path): Work1Response
    {
        if (str_starts_with($path, '/admin/trx-merchants?')) {
            $provider = $this->mismatch === 'provider' ? 'another' : '0192b7e0-6a1c-7f3e-9a51-2c4d5e6f7a8b';
            $method = $this->mismatch === 'method' ? 'card' : 'qrmaster';
            $merchant = $this->mismatch === 'merchant' ? 'Real merchant' : ($this->live ? 'QRMaster' : 'QRMaster (test)');
            $qr = $this->live ? 'https://qr.nspk.ru/live-qr?sum=150000' : 'https://docs.qrmaster.co/test-payment/'.$provider;
            $cells = ['ptest-payment', 'external-id', 'date', 'import', $method, '1500.00', '', '', '', '', '',
                '<span data-copy-value="'.$qr.'"></span>', $merchant];

            return new Work1Response(200, '<table><tr data-key="123"><td>'.implode('</td><td>', $cells).'</td></tr></table>');
        }
        if (str_starts_with($path, '/admin/trx-merchants/update?')) {
            $amount = $this->mismatch === 'amount' ? '1500.001' : '1500.0000';
            $options = '';
            foreach (['1' => 'pending', '2' => 'in_check', '3' => 'dispute', '4' => 'expired', '5' => 'success', '6' => 'cancel'] as $value => $label) {
                $options .= '<option value="'.$value.'"'.((string) $value === $this->status ? ' selected' : '').'>'.$label.'</option>';
            }

            $button = $this->mismatch === 'completion_action' ? '' : '<button type="submit" name="save" value="save_and_colback">Save and Update Status</button>';

            return new Work1Response(200, '<form action="/admin/trx-merchants/update?id=ptest-payment"><input name="DealForm[amount]" value="'.$amount.'"><input name="DealForm[recipient]" value="'.($this->live ? 'QRMaster' : 'QRMaster Test').'"><input name="DealForm[user_id]" value="'.$this->traderId.'"><input name="DealForm[rate]" value="84.28"><input name="DealForm[commission]" value="14.00"><input name="DealForm[card_id]" value="14768"><textarea name="DealForm[comment]">Existing note</textarea><select name="DealForm[status]">'.$options.'</select>'.$button.'</form>');
        }
        if ($path === '/admin/site/update?id=1') {
            return new Work1Response(200, '<form action="/admin/site/login-as?id=1"><input name="mfa_code"></form>');
        }
        if (str_starts_with($path, '/site/login-as?')) {
            return new Work1Response(302, '', '/cabinet/payin');
        }
        if (str_starts_with($path, '/cabinet/payout?')) {
            $loggedIn = $this->mismatch === 'session' ? 28 : 1;

            return new Work1Response(200, '<a>test (ID: '.$loggedIn.')</a><table><tr data-id="123" data-user="'.$this->traderId.'" data-status="'.$this->status.'" data-type="import"></tr></table>');
        }
        throw new \RuntimeException('Unexpected GET '.$path);
    }

    public function post(string $path, array $fields, bool $ajax = false): Work1Response
    {
        if ($path === '/admin/trx-merchants/update?id=ptest-payment' && ! $ajax && ($fields['save'] ?? null) === 'save_and_colback') {
            $this->writes++;
            $this->completionFields = $fields;
            $this->postedStatuses[] = $fields['DealForm[status]'];
            if (! $this->ignoreWrite) {
                $this->status = $fields['DealForm[status]'];
            }
            if ($this->loseResponse) {
                throw new Work1Exception('Response lost');
            }

            return new Work1Response(302, '', '/admin/trx-merchants');
        }
        if ($path === '/admin/site/login-as?id=1') {
            if ($this->refuseLogin) {
                return new Work1Response(200, '<div class="alert">Неверный код</div>', '/admin/site/login-as?id=1&token=secret-token');
            }

            return new Work1Response(302, '', '/site/login-as?token=fake');
        }
        if ($path === '/cabinet/post' && $ajax && $fields['change_status[id]'] === 123) {
            if ($fields['change_status[status]'] === '5') {
                return new Work1Response(403, '{"message":"Receipt required"}');
            }
            $this->writes++;
            $this->postedStatuses[] = $fields['change_status[status]'];
            if (! $this->ignoreWrite) {
                $this->status = $fields['change_status[status]'];
                $this->traderId = 1;
            }
            if ($this->loseResponse) {
                throw new Work1Exception('Response lost');
            }

            return new Work1Response(200, '{}');
        }
        throw new \RuntimeException('Unexpected POST '.$path);
    }
}
