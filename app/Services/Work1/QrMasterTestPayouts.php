<?php

namespace App\Services\Work1;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class QrMasterTestPayouts
{
    public function __construct(private readonly Work1Transport $transport, private readonly FormParser $parser) {}

    public function sync(string $paymentId, string $providerId, string $amount, int $traderId, string $action, bool $reconcileOnly, string $mode = 'test', ?string $qrHash = null): string
    {
        if (! in_array($mode, ['test', 'live'], true) || ($mode === 'live' && ! preg_match('/^[a-f0-9]{64}$/D', (string) $qrHash))) {
            throw new Work1Exception('QRMASTER_PAYOUT_MODE_MISMATCH');
        }

        return Cache::lock('qrmaster:trader-session', 180)->get(function () use ($paymentId, $providerId, $amount, $traderId, $action, $reconcileOnly, $mode, $qrHash): string {
            $snapshot = $this->snapshot($paymentId, $providerId, $amount, $mode, $qrHash);
            if ($snapshot['trader_id'] !== 0 && $snapshot['trader_id'] !== $traderId) {
                return 'assigned_elsewhere';
            }
            if ($action === 'claim' && $snapshot['trader_id'] === $traderId && in_array($snapshot['status'], ['2', '5'], true)) {
                return 'assigned';
            }
            if ($action === 'complete' && $snapshot['status'] === '5' && $snapshot['trader_id'] === $traderId) {
                return 'success';
            }
            if (! in_array($snapshot['status'], ['1', '2'], true)) {
                return 'terminal';
            }
            if ($action === 'complete' && ($snapshot['trader_id'] !== $traderId || $snapshot['status'] !== '2')) {
                return 'waiting_for_trader';
            }
            if ($action === 'claim' && ($snapshot['status'] !== '1' || $snapshot['trader_id'] !== 0)) {
                return 'unknown';
            }
            $intentKey = 'qrmaster:'.$action.':'.$paymentId;
            if ($reconcileOnly || Cache::has($intentKey)) {
                return 'unknown';
            }
            if ($action === 'complete') {
                return $this->completeConfirmedPayment($snapshot, $paymentId, $providerId, $amount, $traderId, $intentKey, $mode, $qrHash);
            }
            $this->loginAsTrader($traderId);
            $page = $this->transport->get('/cabinet/payout?'.http_build_query(['filter_id' => $paymentId]));
            if ($page->status !== 200 || ! preg_match('/\(ID:\s*'.preg_quote((string) $traderId, '/').'\)/', strip_tags($page->body))) {
                throw new Work1Exception('QRMASTER_TRADER_SESSION_MISMATCH');
            }
            $xpath = $this->xpath($page->body);
            $rows = $xpath->query('//tr[@data-id="'.$snapshot['deal_id'].'"]');
            if ($rows->length !== 1) {
                return 'unavailable';
            }
            $row = $rows->item(0);
            if ($row->getAttribute('data-type') !== 'import' || $row->getAttribute('data-status') !== $snapshot['status']) {
                return 'unknown';
            }
            if ($action === 'complete' && (int) $row->getAttribute('data-user') !== $traderId) {
                return 'assigned_elsewhere';
            }
            Cache::forever($intentKey, ['trader_id' => $traderId, 'provider_id' => $providerId, 'created_at' => now()->toIso8601String()]);
            $response = $this->transport->post('/cabinet/post', [
                'change_status[id]' => $snapshot['deal_id'],
                'change_status[status]' => '2',
                'change_status[price]' => '',
            ], ajax: true);
            $this->recordResponse($intentKey, $response);
            $fresh = $this->snapshot($paymentId, $providerId, $amount, $mode, $qrHash);
            if ($fresh['trader_id'] !== $traderId) {
                return $fresh['trader_id'] > 0 ? 'assigned_elsewhere' : 'unknown';
            }

            return match (true) {
                $action === 'claim' && $fresh['status'] === '2' => 'assigned',
                $action === 'complete' && $fresh['status'] === '5' => 'success',
                default => 'unknown',
            };
        }) ?? 'busy';
    }

    /**
     * @param  array{deal_id: int, trader_id: int, status: string, form: array{path: string, fields: array<string, string>}, can_complete: bool}  $snapshot
     */
    private function completeConfirmedPayment(array $snapshot, string $paymentId, string $providerId, string $amount, int $traderId, string $intentKey, string $mode, ?string $qrHash): string
    {
        if (! $snapshot['can_complete']) {
            throw new Work1Exception('QRMASTER_COMPLETION_CONTRACT_CHANGED');
        }
        $fields = $snapshot['form']['fields'];
        $fields['DealForm[status]'] = '5';
        $comment = 'QRMaster '.$mode.': paid verified; payment '.$providerId.'; amount '.$amount.' RUB; trader '.$traderId;
        $fields['DealForm[comment]'] = trim(($fields['DealForm[comment]'] ?? '')."\n".$comment);
        $fields['save'] = 'save_and_colback';
        Cache::forever($intentKey, ['trader_id' => $traderId, 'provider_id' => $providerId,
            'operation' => 'admin_completion', 'created_at' => now()->toIso8601String()]);
        $response = $this->transport->post($snapshot['form']['path'], $fields);
        $this->recordResponse($intentKey, $response);
        $fresh = $this->snapshot($paymentId, $providerId, $amount, $mode, $qrHash);
        if ($fresh['trader_id'] !== $traderId) {
            return $fresh['trader_id'] > 0 ? 'assigned_elsewhere' : 'unknown';
        }

        return $fresh['status'] === '5' ? 'success' : 'unknown';
    }

    private function recordResponse(string $intentKey, Work1Response $response): void
    {
        Cache::forever($intentKey, array_merge(Cache::get($intentKey, []), ['response_status' => $response->status]));
    }

    /** @return array{deal_id: int, trader_id: int, status: string, form: array{path: string, fields: array<string, string>}, can_complete: bool} */
    private function snapshot(string $paymentId, string $providerId, string $amount, string $mode, ?string $qrHash): array
    {
        $page = $this->transport->get('/admin/trx-merchants?'.http_build_query(['DealsSearch' => ['order_id' => $paymentId]]));
        if ($page->status !== 200) {
            throw new Work1Exception('QRMASTER_PAYOUT_UNAVAILABLE');
        }
        $xpath = $this->xpath($page->body);
        $rows = $xpath->query('//tr[@data-key]');
        if ($rows->length !== 1) {
            throw new Work1Exception('QRMASTER_PAYOUT_IDENTITY_MISMATCH');
        }
        $row = $rows->item(0);
        $cells = $xpath->query('./td', $row);
        $text = fn (int $index): string => trim($cells->item($index)?->textContent ?? '');
        if ($cells->length < 13 || $text(0) !== $paymentId || $text(3) !== 'import' || $text(4) !== 'qrmaster'
            || $text(12) !== ($mode === 'test' ? 'QRMaster (test)' : 'QRMaster') || ! ctype_digit($row->getAttribute('data-key'))) {
            throw new Work1Exception('QRMASTER_PAYOUT_IDENTITY_MISMATCH');
        }
        $requisites = $xpath->query('.//*[@data-copy-value]', $cells->item(11));
        if ($requisites->length !== 1) {
            throw new Work1Exception('QRMASTER_PAYOUT_QR_MISMATCH');
        }
        $qr = $requisites->item(0)->getAttribute('data-copy-value');
        if (($mode === 'test' && $qr !== 'https://docs.qrmaster.co/test-payment/'.$providerId)
            || ($mode === 'live' && ! hash_equals((string) $qrHash, hash('sha256', $qr)))) {
            throw new Work1Exception('QRMASTER_PAYOUT_QR_MISMATCH');
        }
        $page = $this->transport->get('/admin/trx-merchants/update?id='.rawurlencode($paymentId));
        $form = $this->parser->formContaining($page->body, 'DealForm[status]');
        $fields = $form['fields'] ?? [];
        if ($page->status !== 200 || ($form['path'] ?? null) !== '/admin/trx-merchants/update?id='.$paymentId
            || $this->kopecks($fields['DealForm[amount]'] ?? '') !== $this->kopecks($amount)
            || ($fields['DealForm[recipient]'] ?? null) !== ($mode === 'test' ? 'QRMaster Test' : 'QRMaster')) {
            throw new Work1Exception('QRMASTER_PAYOUT_IDENTITY_MISMATCH');
        }
        $status = $fields['DealForm[status]'] ?? '';
        $options = array_column($this->parser->selectOptions($page->body, 'DealForm[status]'), 'label', 'value');
        if (($options[1] ?? null) !== 'pending' || ($options[2] ?? null) !== 'in_check' || ($options[5] ?? null) !== 'success') {
            throw new Work1Exception('QRMASTER_STATUS_CONTRACT_CHANGED');
        }

        return ['deal_id' => (int) $row->getAttribute('data-key'), 'trader_id' => (int) ($fields['DealForm[user_id]'] ?? 0),
            'status' => $status, 'form' => $form,
            'can_complete' => $this->xpath($page->body)->query('//form//button[@type="submit" and @name="save" and @value="save_and_colback"]')->length === 1];
    }

    private function loginAsTrader(int $traderId): void
    {
        $page = $this->transport->get('/admin/site/update?id='.$traderId);
        $form = $this->parser->formContaining($page->body, 'mfa_code');
        if ($page->status !== 200 || ($form['path'] ?? null) !== '/admin/site/login-as?id='.$traderId || blank(config('work1.totp_secret'))) {
            throw new Work1Exception('QRMASTER_TRADER_LOGIN_UNAVAILABLE');
        }
        $fields = $form['fields'];
        $fields['mfa_code'] = Work1Totp::code((string) config('work1.totp_secret'));
        $response = $this->transport->post($form['path'], $fields);
        $location = $response->location ?? '';
        $host = parse_url($location, PHP_URL_HOST);
        $locationPath = $this->locationPath($location);
        if ($response->status !== 302 || parse_url($location, PHP_URL_PATH) !== '/site/login-as'
            || ($host !== null && $host !== parse_url((string) config('work1.base_url'), PHP_URL_HOST))) {
            Log::warning('[QrMasterTestPayouts.loginAsTrader] refused', [
                'trader_id' => $traderId,
                'http_status' => $response->status,
                'location_path' => $locationPath,
                'alert' => $this->parser->alert($response->body),
            ]);
            throw new Work1Exception('QRMASTER_TRADER_LOGIN_FAILED');
        }
        Log::info('[QrMasterTestPayouts.loginAsTrader] entered', [
            'trader_id' => $traderId,
            'location_path' => $locationPath,
        ]);
        $this->transport->get($location);
    }

    private function locationPath(?string $location): ?string
    {
        if ($location === null || $location === '') {
            return null;
        }
        $path = parse_url($location, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function xpath(string $html): DOMXPath
    {
        if ($html === '') {
            throw new Work1Exception('QRMASTER_EMPTY_RESPONSE');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function kopecks(string $amount): int
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/D', $amount, $matches)) {
            throw new Work1Exception('QRMASTER_INVALID_AMOUNT');
        }
        $fraction = str_pad($matches[2] ?? '', 4, '0');
        if (substr($fraction, 2) !== '00') {
            throw new Work1Exception('QRMASTER_INVALID_AMOUNT');
        }

        return (int) $matches[1] * 100 + (int) substr($fraction, 0, 2);
    }
}
