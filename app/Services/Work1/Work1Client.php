<?php

namespace App\Services\Work1;

class Work1Client
{
    public function __construct(
        private readonly Work1Transport $transport,
        private readonly FormParser $parser,
    ) {}

    public function ensure(): void
    {
        $this->transport->ensure();
    }

    public function submitOtp(string $otp): void
    {
        $this->transport->submitOtp($otp);
    }

    /**
     * @return array{offer_id:int, merchant_code:string, method:string, agent:?string, created:bool}
     */
    public function createOffer(string $merchantCode, string $merchantName, string $method): array
    {
        $existing = $this->findOffer($merchantCode, $method);
        if ($existing !== null) {
            return [
                'offer_id' => $existing['id'],
                'merchant_code' => $merchantCode,
                'method' => $method,
                'agent' => $existing['agent'],
                'created' => false,
            ];
        }

        $page = $this->guard($this->transport->get('/admin/offers/create'));
        $fields = $this->parser->fields($page->body);
        $fields['OfferForm[merchant_code]'] = $merchantCode;
        $fields['OfferForm[merchant_name]'] = $merchantName;
        $fields['OfferForm[method]'] = $method;

        $posted = $this->guard($this->transport->post('/admin/offers/create', $fields));
        $id = $this->parser->offerIdFromLocation($posted->location);
        if ($id === null) {
            $id = $this->findOffer($merchantCode, $method)['id'] ?? null;
        }
        if ($id === null) {
            throw new Work1Exception($this->parser->alert($posted->body) ?? 'work1 не вернул id оффера');
        }

        return [
            'offer_id' => $id,
            'merchant_code' => $merchantCode,
            'method' => $method,
            'agent' => null,
            'created' => true,
        ];
    }

    /**
     * @return array{offer_id:int, merchant_code:?string, merchant_name:?string, method:?string, agent:?string, oracle_source:mixed, oracle_source_payout:mixed, trader:?array}
     */
    public function getOffer(int $id): array
    {
        $page = $this->guard($this->transport->get('/admin/offers/update?id='.$id));
        $fields = $this->parser->fields($page->body);
        $code = $this->stringOrNull($fields['OfferForm[merchant_code]'] ?? null);
        $method = $this->stringOrNull($fields['OfferForm[method]'] ?? null);
        $agent = null;
        if ($code !== null) {
            $row = $this->findOffer($code, $method);
            if ($row !== null && $row['id'] === $id) {
                $agent = $row['agent'];
            }
        }

        return [
            'offer_id' => $id,
            'merchant_code' => $code,
            'merchant_name' => $this->stringOrNull($fields['OfferForm[merchant_name]'] ?? null),
            'method' => $method,
            'agent' => $agent,
            'oracle_source' => $this->parser->jsonOrRaw($fields['OfferForm[oracle_source]'] ?? null),
            'oracle_source_payout' => $this->parser->jsonOrRaw($fields['OfferForm[oracle_source_payout]'] ?? null),
            'trader' => $this->trader($id),
        ];
    }

    /**
     * @return array{offer_id:int, admin_id:int, attached:bool}
     */
    public function attachAgent(int $offerId, int $adminId): array
    {
        $offer = $this->getOffer($offerId);
        if ($offer['agent'] === 'superadmin') {
            return ['offer_id' => $offerId, 'admin_id' => $adminId, 'attached' => true];
        }

        $this->guard($this->transport->post('/admin/offers/switch-status?id='.$adminId.'&offer_id='.$offerId, [
            'admin_id' => $adminId,
            'switch' => 1,
        ], true));

        return [
            'offer_id' => $offerId,
            'admin_id' => $adminId,
            'attached' => $this->getOffer($offerId)['agent'] === 'superadmin',
        ];
    }

    /**
     * @return array{offer_id:int, user_id:int, payin_com:?string, payout_com:?string}
     */
    public function setCommission(int $offerId, int $userId, float $maxPayin, float $maxPayout, float $payin, float $payout): array
    {
        foreach ([
            'max_payin_com' => $maxPayin,
            'min_payin_com' => 0,
            'max_payout_com' => $maxPayout,
            'min_payout_com' => 0,
        ] as $field => $value) {
            $this->guard($this->transport->post('/admin/admin/update-commission?id=1', [
                'offer_id' => $offerId,
                $field => $this->decimal($value),
            ], true));
        }

        foreach (['payin_com' => $payin, 'payout_com' => $payout] as $field => $value) {
            $this->guard($this->transport->post('/admin/site/update-commission?id='.$userId, [
                'offer_id' => $offerId,
                'user_id' => $userId,
                $field => $this->decimal($value),
            ], true));
        }

        $trader = $this->trader($offerId);

        return [
            'offer_id' => $offerId,
            'user_id' => $userId,
            'payin_com' => $trader['payin_com'] ?? $this->decimal($payin),
            'payout_com' => $trader['payout_com'] ?? $this->decimal($payout),
        ];
    }

    /**
     * @return array{offer_id:int, user_id:int, payin_enabled:?bool, payout_enabled:?bool}
     */
    public function enableTrader(int $offerId, int $userId): array
    {
        $offerPage = $this->guard($this->transport->get('/admin/offers/update?id='.$offerId));
        $enabled = $this->parser->userEnabled($offerPage->body, $userId);
        if ($enabled === null) {
            throw new Work1Exception('Не вижу тумблер трейдера на оффере');
        }
        if ($enabled === false) {
            $this->guard($this->transport->post('/admin/offers/switch-status-agent?id='.$offerId, [
                'offer_id' => $offerId,
                'user_id' => $userId,
                'switch' => 1,
            ], true));
        }

        $row = $this->requireTrader($offerId);
        foreach (['payin' => 'payin_enabled', 'payout' => 'payout_enabled'] as $type => $key) {
            if ($row[$key] === false) {
                $this->guard($this->transport->post('/admin/offers/toggle-payment-type', [
                    'user_id' => $userId,
                    'offer_id' => $offerId,
                    'action' => 'enable',
                    'type' => $type,
                ], true));
            }
        }

        $fresh = $this->requireTrader($offerId);

        return [
            'offer_id' => $offerId,
            'user_id' => $userId,
            'payin_enabled' => $fresh['payin_enabled'],
            'payout_enabled' => $fresh['payout_enabled'],
        ];
    }

    /**
     * @return array{offer_id:int, oracle_source:mixed, oracle_source_payout:mixed}
     */
    public function setRate(int $offerId, string $preset, string $market = 'p2p', string $side = 'buy'): array
    {
        $page = $this->guard($this->transport->get('/admin/offers/update?id='.$offerId));
        $fields = $this->parser->fields($page->body);
        $payload = json_encode([
            'market' => $market,
            'preset' => $preset,
            'side' => $side,
        ], JSON_UNESCAPED_SLASHES);
        $fields['OfferForm[oracle_source]'] = $payload;
        $fields['OfferForm[oracle_source_payout]'] = $payload;

        $this->guard($this->transport->post('/admin/offers/update?id='.$offerId, $fields));
        $again = $this->guard($this->transport->get('/admin/offers/update?id='.$offerId));
        $saved = $this->parser->fields($again->body);

        return [
            'offer_id' => $offerId,
            'oracle_source' => $this->parser->jsonOrRaw($saved['OfferForm[oracle_source]'] ?? null),
            'oracle_source_payout' => $this->parser->jsonOrRaw($saved['OfferForm[oracle_source_payout]'] ?? null),
        ];
    }

    /**
     * @return array{requisite_id:int, archive:bool, status:?string}
     */
    public function unarchiveRequisite(int $requisiteId): array
    {
        $page = $this->guard($this->transport->get('/admin/requisite/update?id='.$requisiteId));
        $fields = $this->parser->fields($page->body);
        $fields['RequisiteUpdate[archive]'] = '0';
        $fields['RequisiteUpdate[status]'] = '1';

        $this->guard($this->transport->post('/admin/requisite/update?id='.$requisiteId, $fields));
        $again = $this->guard($this->transport->get('/admin/requisite/update?id='.$requisiteId));
        $state = $this->parser->requisiteState($again->body);

        return [
            'requisite_id' => $requisiteId,
            'archive' => $state['archive'],
            'status' => $state['status'],
        ];
    }

    /**
     * @return array{page:int, banks:list<array{id:int, cells:array<int|string, string>}>}
     */
    public function listBanks(int $page = 1): array
    {
        $path = '/admin/bank/index'.($page > 1 ? '?page='.$page : '');
        $listed = $this->guard($this->transport->get($path));
        $banks = $this->parser->gridRows($listed->body);
        $this->log('info', 'listBanks', 'read', ['page' => $page, 'count' => count($banks)]);

        return ['page' => $page, 'banks' => $banks];
    }

    /**
     * @return array{bank_id:int, fields:array<string, string>}
     */
    public function getBank(int $id): array
    {
        $page = $this->guard($this->transport->get('/admin/bank/update?id='.$id));
        $this->log('info', 'getBank', 'read', ['bank_id' => $id]);

        return [
            'bank_id' => $id,
            'fields' => $this->parser->publicFields($this->parser->fields($page->body)),
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{bank_id:int, fields:array<string, string>}
     */
    public function createBank(array $values): array
    {
        $this->log('info', 'createBank', 'read create form', ['keys' => array_keys($values)]);
        $fields = $this->fill('/admin/bank/create', $values);
        $posted = $this->guard($this->transport->post('/admin/bank/create', $fields));
        $id = $this->parser->offerIdFromLocation($posted->location);
        if ($id === null) {
            throw new Work1Exception($this->parser->alert($posted->body) ?? 'work1 не вернул id банка');
        }
        $this->log('info', 'createBank', 'created', ['bank_id' => $id]);

        return $this->getBank($id);
    }

    /**
     * @param  array<string, string>  $values
     * @return array{bank_id:int, fields:array<string, string>}
     */
    public function updateBank(int $id, array $values): array
    {
        $path = '/admin/bank/update?id='.$id;
        $this->log('info', 'updateBank', 'read update form', ['bank_id' => $id, 'keys' => array_keys($values)]);
        $fields = $this->fill($path, $values);
        $this->guard($this->transport->post($path, $fields));

        return $this->getBank($id);
    }

    /**
     * @return array{page:int, methods:list<array{id:int, cells:array<int|string, string>}>}
     */
    public function listMethods(?string $name, int $page = 1): array
    {
        $query = [];
        if ($name !== null && $name !== '') {
            $query['MethodsSearch'] = ['name' => $name];
        }
        if ($page > 1) {
            $query['page'] = $page;
        }
        $path = '/admin/methods/index';
        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }
        $listed = $this->guard($this->transport->get($path));
        $methods = $this->parser->gridRows($listed->body);
        $this->log('info', 'listMethods', 'read', ['name' => $name, 'page' => $page, 'count' => count($methods)]);

        return ['page' => $page, 'methods' => $methods];
    }

    /**
     * @return array{method_id:int, name:?string, method_type_id:?string, method_type:?string, banks:list<array{id:string, name:string}>, fields:array<string, string>}
     */
    public function getMethod(int $id): array
    {
        $page = $this->guard($this->transport->get('/admin/methods/update?id='.$id));
        $method = $this->readMethod($id, $page->body);
        $this->log('info', 'getMethod', 'read', [
            'method_id' => $id,
            'name' => $method['name'],
            'banks' => count($method['banks']),
        ]);

        return $method;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{method_id:int, name:?string, method_type_id:?string, method_type:?string, banks:list<array{id:string, name:string}>, fields:array<string, string>, created:bool}
     */
    public function createMethod(array $values): array
    {
        $name = trim($values['name'] ?? '');
        if ($name === '') {
            throw new Work1Exception('У метода кабинета нет имени');
        }
        $existing = $this->findMethod($name);
        if ($existing !== null) {
            $method = $this->getMethod($existing);
            $method['created'] = false;
            $this->log('info', 'createMethod', 'already exists', ['method_id' => $existing, 'name' => $name]);

            return $method;
        }

        $this->log('info', 'createMethod', 'read create form', ['name' => $name, 'keys' => array_keys($values)]);
        $page = $this->guard($this->transport->get('/admin/methods/create'));
        $fields = $this->methodFields($page->body, $values, []);
        $posted = $this->guard($this->transport->post('/admin/methods/create', $fields));
        $id = $this->parser->offerIdFromLocation($posted->location) ?? $this->findMethod($name);
        if ($id === null) {
            throw new Work1Exception($this->parser->alert($posted->body) ?? 'work1 не вернул id метода');
        }
        $this->log('info', 'createMethod', 'created', ['method_id' => $id, 'name' => $name]);
        $method = $this->getMethod($id);
        $method['created'] = true;

        return $method;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{method_id:int, name:?string, method_type_id:?string, method_type:?string, banks:list<array{id:string, name:string}>, fields:array<string, string>}
     */
    public function updateMethod(int $id, array $values): array
    {
        $path = '/admin/methods/update?id='.$id;
        $this->log('info', 'updateMethod', 'read update form', ['method_id' => $id, 'keys' => array_keys($values)]);
        $page = $this->guard($this->transport->get($path));
        $selected = [];
        foreach ($this->parser->selectOptions($page->body, 'Methods[bank_ids][]') as $option) {
            if ($option['selected'] && $option['value'] !== '') {
                $selected[] = $option['value'];
            }
        }
        $fields = $this->methodFields($page->body, $values, $selected);
        $this->guard($this->transport->post($path, $fields));

        return $this->getMethod($id);
    }

    /**
     * @return array{user_id:?int, page:int, requisites:list<array{id:int, cells:array<int|string, string>}>}
     */
    public function listRequisites(?int $userId, int $page = 1): array
    {
        $query = [];
        if ($userId !== null) {
            $query['RequisiteSearch'] = ['user' => $userId];
        }
        if ($page > 1) {
            $query['page'] = $page;
        }
        $path = '/admin/requisite/index';
        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }
        $listed = $this->guard($this->transport->get($path));
        $rows = $this->parser->gridRows($listed->body);
        $this->log('info', 'listRequisites', 'read', [
            'user_id' => $userId,
            'page' => $page,
            'count' => count($rows),
        ]);

        return ['user_id' => $userId, 'page' => $page, 'requisites' => $rows];
    }

    /**
     * @return array{requisite_id:int, archive:bool, status:?string, fields:array<string, string>}
     */
    public function getRequisite(int $id): array
    {
        $page = $this->guard($this->transport->get('/admin/requisite/update?id='.$id));
        $fields = $this->parser->fields($page->body);
        $state = $this->parser->requisiteState($page->body);
        $this->log('info', 'getRequisite', 'read', [
            'requisite_id' => $id,
            'archive' => $state['archive'],
            'status' => $state['status'],
        ]);

        return [
            'requisite_id' => $id,
            'archive' => $state['archive'],
            'status' => $state['status'],
            'fields' => $this->parser->publicFields($fields),
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{requisite_id:int, archive:bool, status:?string, fields:array<string, string>}
     */
    public function createRequisite(array $values): array
    {
        $this->log('info', 'createRequisite', 'read index', ['keys' => array_keys($values)]);
        $index = $this->guard($this->transport->get('/admin/requisite/index'));
        $path = $this->parser->requisiteCreatePath($index->body);
        if ($path !== null) {
            $fields = $this->fill($path, $values);
            $postPath = $path;
        } else {
            $form = $this->parser->requisiteCreateForm($index->body);
            if ($form === null) {
                throw new Work1Exception('В кабинете work1 нет формы создания карты. Можно править получателя и лимиты существующей карты');
            }
            $fields = $this->parser->applyValues($form['fields'], $values);
            $postPath = $form['path'];
        }

        $posted = $this->guard($this->transport->post($postPath, $fields));
        $id = $this->parser->offerIdFromLocation($posted->location);
        if ($id === null) {
            throw new Work1Exception($this->parser->alert($posted->body) ?? 'work1 не вернул id карты');
        }
        $this->log('info', 'createRequisite', 'created', ['requisite_id' => $id]);

        return $this->getRequisite($id);
    }

    /**
     * @param  array<string, string>  $values
     * @return array{requisite_id:int, archive:bool, status:?string, fields:array<string, string>}
     */
    public function updateRequisite(int $id, array $values): array
    {
        $path = '/admin/requisite/update?id='.$id;
        $this->log('info', 'updateRequisite', 'read update form', ['requisite_id' => $id, 'keys' => array_keys($values)]);
        $fields = $this->fill($path, $values);
        $this->guard($this->transport->post($path, $fields));

        return $this->getRequisite($id);
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function fill(string $path, array $values): array
    {
        $page = $this->guard($this->transport->get($path));
        $fields = $this->parser->fields($page->body);
        foreach (array_keys($fields) as $name) {
            if (str_contains($name, 'newPatternIndex') || str_contains($name, '+')) {
                unset($fields[$name]);
            }
        }

        return $this->parser->applyValues($fields, $values);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $method, string $message, array $context = []): void
    {
        if (! function_exists('app')) {
            return;
        }

        try {
            if (! app()->bound('log')) {
                return;
            }
            \Illuminate\Support\Facades\Log::log($level, '[Work1Client.'.$method.'] '.$message, $context);
        } catch (\Throwable) {
            // Unit tests build the client without the framework log binding.
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $keepBanks
     * @return array<string, scalar|list<string>|null>
     */
    private function methodFields(string $html, array $values, array $keepBanks): array
    {
        $fields = $this->parser->fields($html);
        unset($fields['Methods[bank_ids]'], $fields['Methods[bank_ids][]']);
        $banks = array_key_exists('bank_ids', $values) ? $this->bankIds($values['bank_ids']) : $keepBanks;
        if ($banks !== []) {
            $fields['Methods[bank_ids][]'] = $banks;
        }

        $type = $values['method_type_id'] ?? $values['method_type'] ?? null;
        unset($values['bank_ids'], $values['method_type'], $values['method_type_id']);
        if (is_string($type) && $type !== '') {
            $values['method_type_id'] = $this->resolveMethodType($html, $type);
        }

        return $this->parser->applyValues($fields, $values);
    }

    /**
     * @return array{method_id:int, name:?string, method_type_id:?string, method_type:?string, banks:list<array{id:string, name:string}>, fields:array<string, string>}
     */
    private function readMethod(int $id, string $html): array
    {
        $fields = $this->parser->publicFields($this->parser->fields($html));
        unset($fields['Methods[bank_ids]'], $fields['Methods[bank_ids][]']);
        $banks = [];
        $typeId = null;
        $type = null;
        foreach ($this->parser->selectOptions($html, 'Methods[bank_ids][]') as $option) {
            if ($option['selected'] && $option['value'] !== '') {
                $banks[] = ['id' => $option['value'], 'name' => $option['label']];
            }
        }
        foreach ($this->parser->selectOptions($html, 'Methods[method_type_id]') as $option) {
            if ($option['selected']) {
                $typeId = $option['value'] !== '' ? $option['value'] : null;
                $type = $option['label'] !== '' ? $option['label'] : null;
            }
        }

        return [
            'method_id' => $id,
            'name' => $this->stringOrNull($fields['Methods[name]'] ?? null),
            'method_type_id' => $typeId,
            'method_type' => $type,
            'banks' => $banks,
            'fields' => $fields,
        ];
    }

    private function resolveMethodType(string $html, string $given): string
    {
        if (preg_match('/^\d+$/', $given) === 1) {
            return $given;
        }
        foreach ($this->parser->selectOptions($html, 'Methods[method_type_id]') as $option) {
            if ($option['value'] !== '' && strcasecmp($option['label'], $given) === 0) {
                return $option['value'];
            }
        }

        throw new Work1Exception('В кабинете нет типа метода '.$given);
    }

    /**
     * @return list<string>
     */
    private function bankIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            if (preg_match('/^\d+$/', $part) !== 1) {
                throw new Work1Exception('bank_ids — это id банков через запятую');
            }
            $ids[] = $part;
        }

        return $ids;
    }

    private function findMethod(string $name): ?int
    {
        $query = http_build_query(['MethodsSearch' => ['name' => $name]]);
        $page = $this->guard($this->transport->get('/admin/methods/index?'.$query));
        foreach ($this->parser->gridRows($page->body) as $row) {
            $label = $row['cells']['Methods'] ?? null;
            if ($label === $name) {
                return $row['id'];
            }
        }

        return null;
    }

    /**
     * @return array{id:int, merchant_code:?string, method:?string, agent:?string}|null
     */
    private function findOffer(string $merchantCode, ?string $method): ?array
    {
        $query = http_build_query(['OfferSearch' => ['merchant_code' => $merchantCode]]);
        $page = $this->guard($this->transport->get('/admin/offers/index?'.$query));
        foreach ($this->parser->indexRows($page->body) as $row) {
            if ($row['merchant_code'] !== $merchantCode) {
                continue;
            }
            if ($method !== null && $method !== '' && $row['method'] !== $method) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @return array{payin_com:?string, payout_com:?string, payin_enabled:?bool, payout_enabled:?bool}|null
     */
    private function trader(int $offerId): ?array
    {
        $page = $this->guard($this->transport->get('/admin/site/update?id=1'));

        return $this->parser->traderRow($page->body, $offerId);
    }

    /**
     * @return array{payin_com:?string, payout_com:?string, payin_enabled:?bool, payout_enabled:?bool}
     */
    private function requireTrader(int $offerId): array
    {
        $row = $this->trader($offerId);
        if ($row === null) {
            throw new Work1Exception('На оффере нет строки трейдера. Сначала повесь агента superadmin');
        }

        return $row;
    }

    private function guard(Work1Response $response): Work1Response
    {
        $location = (string) $response->location;
        if (str_contains($response->body, 'OtpForm[otp]') || str_contains($location, 'mfa-verify')) {
            throw new MfaRequiredException;
        }
        if (str_contains($response->body, 'LoginForm[username]') || str_contains($location, '/admin/site/login')) {
            throw new Work1Exception('Сессия work1 протухла');
        }
        if ($response->status >= 400) {
            throw new Work1Exception($this->parser->alert($response->body) ?? 'work1 ответил '.$response->status);
        }

        return $response;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
