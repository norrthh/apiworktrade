<?php

namespace App\Services\Work1;

/**
 * Реквизиты трейдерского кабинета /cabinet/cards.
 * Админские /admin/requisite сюда не ходят: другая сессия и другой CSRF.
 */
class CabinetClient
{
    public function __construct(
        private readonly CabinetTransport $transport,
        private readonly FormParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{deleted:bool, requisites:list<array<string, mixed>>}
     */
    public function listCards(array $filters = []): array
    {
        $deleted = (bool) ($filters['deleted'] ?? false);
        $page = $this->guard($this->transport->get($this->listPath($filters, $deleted)));
        $cards = $this->cards($page->body, $deleted);
        $this->log('listCards', ['deleted' => $deleted, 'count' => count($cards)]);

        return ['deleted' => $deleted, 'requisites' => $cards];
    }

    /**
     * @return array{requisite_id:int, method:?string, bank_id:?string, device_id:?string, recipient:?string, fields:array<string, string>}
     */
    public function getCard(int $id): array
    {
        $fields = $this->parser->fields($this->updateForm($id));
        $this->log('getCard', ['requisite_id' => $id]);

        return $this->cardPayload($id, $fields);
    }

    /**
     * @return array{methods:list<array{value:string, label:string}>, devices:list<array{value:string, label:string}>, number_types:mixed}
     */
    public function options(): array
    {
        $page = $this->guard($this->transport->get('/cabinet/cards'));

        return [
            'methods' => $this->choices($page->body, 'RequisitesForm[method]'),
            'devices' => $this->choices($page->body, 'RequisitesForm[device_id]'),
            'number_types' => $this->parser->jsonAssignment($page->body, 'requisite_types'),
        ];
    }

    /**
     * @return array{method:string, banks:list<array{id:int, name:string}>}
     */
    public function banks(string $method): array
    {
        $decoded = $this->json($this->transport->post('/cabinet/get-banks', [
            'method' => $method,
        ], true));
        $banks = [];
        foreach ($decoded['banks'] ?? [] as $bank) {
            if (! is_array($bank) || ! isset($bank['id'], $bank['name'])) {
                continue;
            }
            $banks[] = ['id' => (int) $bank['id'], 'name' => (string) $bank['name']];
        }

        return ['method' => $method, 'banks' => $banks];
    }

    /**
     * @return array{bank_id:int, sender_names:mixed}
     */
    public function senderNames(int $bankId): array
    {
        $decoded = $this->json($this->transport->post('/cabinet/get-bank-sender-names', [
            'bank_id' => (string) $bankId,
        ], true));

        return [
            'bank_id' => $bankId,
            'sender_names' => $decoded['sender_names'] ?? [],
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public function createCard(array $values): array
    {
        $page = $this->guard($this->transport->get('/cabinet/cards'));
        $before = array_column($this->parser->cabinetCards($page->body), 'id');
        $form = $this->parser->formContaining($page->body, 'RequisitesForm[number]');
        if ($form === null) {
            throw new Work1Exception('В кабинете нет формы создания реквизита');
        }

        $fields = $this->parser->applyValues($form['fields'], $values);
        $posted = $this->guard($this->transport->post($form['path'], $fields));
        $landed = $posted->location !== null
            ? $this->guard($this->transport->get($posted->location))
            : $posted;
        $fresh = array_values(array_filter(
            $this->cards($landed->body, false),
            fn (array $card): bool => ! in_array($card['id'], $before, true),
        ));
        if (count($fresh) !== 1) {
            throw new Work1Exception($this->parser->alert($this->parser->htmlFragment($landed->body)) ?? 'work1 не вернул id реквизита');
        }

        $created = $fresh[0];
        $created['created'] = true;
        $this->log('createCard', ['requisite_id' => $created['id']]);

        return $created;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{requisite_id:int, method:?string, bank_id:?string, device_id:?string, recipient:?string, fields:array<string, string>}
     */
    public function updateCard(int $id, array $values): array
    {
        $fields = $this->parser->applyValues($this->parser->fields($this->updateForm($id)), $values);
        $posted = $this->guard($this->transport->post('/cabinet/update-requisite?id='.$id, $fields));
        $fragment = $this->parser->htmlFragment($posted->body);
        $alert = $this->parser->alert($fragment);
        if ($posted->location === null && $alert !== null) {
            throw new Work1Exception($alert);
        }
        $this->log('updateCard', ['requisite_id' => $id, 'keys' => array_keys($values)]);

        return $this->getCard($id);
    }

    /**
     * @return array{requisite_id:int, active:bool}
     */
    public function enableCard(int $id): array
    {
        $this->action('/cabinet/post', ['return_requisite' => (string) $id]);
        $this->log('enableCard', ['requisite_id' => $id]);

        return ['requisite_id' => $id, 'active' => true];
    }

    /**
     * Тумблер «Выключить» шлёт delete_requisite.archive=0.
     * archive=1 — это уже удаление в «Удаленные реквизиты».
     *
     * @return array{requisite_id:int, active:bool}
     */
    public function disableCard(int $id): array
    {
        $this->action('/cabinet/post', [
            'delete_requisite[id]' => (string) $id,
            'delete_requisite[archive]' => '0',
        ]);
        $this->log('disableCard', ['requisite_id' => $id]);

        return ['requisite_id' => $id, 'active' => false];
    }

    /**
     * @return array{requisite_id:int, deleted:bool}
     */
    public function deleteCard(int $id): array
    {
        $this->action('/cabinet/post', [
            'delete_requisite[id]' => (string) $id,
            'delete_requisite[archive]' => '1',
        ]);
        $this->log('deleteCard', ['requisite_id' => $id]);

        return ['requisite_id' => $id, 'deleted' => true];
    }

    /**
     * @param  list<int>  $ids
     * @return array{ids:list<int>, active:bool}
     */
    public function setCardsActive(array $ids, bool $active): array
    {
        $this->action('/cabinet/archive', [
            'requisites_id' => array_map('strval', $ids),
            'archive' => $active ? '0' : '1',
        ]);
        $this->log('setCardsActive', ['count' => count($ids), 'active' => $active]);

        return ['ids' => array_values($ids), 'active' => $active];
    }

    /**
     * @return array{requisite_id:int, message:?string}
     */
    public function resetDailyLimit(int $id): array
    {
        $decoded = $this->json($this->transport->post('/cabinet/reset-daily-limit', [
            'id' => (string) $id,
        ], true));
        $this->log('resetDailyLimit', ['requisite_id' => $id]);

        return [
            'requisite_id' => $id,
            'message' => isset($decoded['message']) ? (string) $decoded['message'] : null,
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{requisite_id:int, method:?string, bank_id:?string, device_id:?string, recipient:?string, fields:array<string, string>}
     */
    private function cardPayload(int $id, array $fields): array
    {
        return [
            'requisite_id' => $id,
            'method' => $this->stringOrNull($fields['RequisitesForm[method]'] ?? null),
            'bank_id' => $this->stringOrNull($fields['RequisitesForm[bank_id]'] ?? null),
            'device_id' => $this->stringOrNull($fields['RequisitesForm[device_id]'] ?? null),
            'recipient' => $this->stringOrNull($fields['RequisitesForm[recipient]'] ?? null),
            'fields' => $this->parser->publicFields($fields),
        ];
    }

    private function updateForm(int $id): string
    {
        $response = $this->guard($this->transport->post('/cabinet/update-requisite?id='.$id, [], true));

        return $this->parser->htmlFragment($response->body);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $fields
     */
    private function action(string $path, array $fields): void
    {
        $response = $this->guard($this->transport->post($path, $fields, true));
        $decoded = json_decode($response->body, true);
        if (is_array($decoded) && (($decoded['success'] ?? null) === false || ($decoded['status'] ?? null) === 'error')) {
            throw new Work1Exception((string) ($decoded['message'] ?? 'work1 отклонил запрос'));
        }
    }

    private function json(Work1Response $response): array
    {
        $response = $this->guard($response);
        $decoded = json_decode($response->body, true);
        if (! is_array($decoded)) {
            throw new Work1Exception($this->parser->alert($response->body) ?? 'work1 вернул не JSON');
        }
        if (($decoded['success'] ?? null) === false || ($decoded['status'] ?? null) === 'error') {
            throw new Work1Exception((string) ($decoded['message'] ?? 'work1 отклонил запрос'));
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cards(string $html, bool $deleted): array
    {
        $cards = $this->parser->cabinetCards($html);
        foreach ($cards as $index => $card) {
            $card['deleted'] = $deleted;
            if ($deleted) {
                $card['active'] = false;
            }
            $cards[$index] = $card;
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function listPath(array $filters, bool $deleted): string
    {
        $path = $deleted ? '/cabinet/deleted-requisites' : '/cabinet/cards';
        $query = [];
        foreach (['archive_status', 'bank_id', 'card_number', 'recipient', 'sort_by'] as $key) {
            $value = $filters[$key] ?? null;
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }
        if ($query === []) {
            return $path;
        }

        return $path.'?'.http_build_query($query);
    }

    /**
     * @return list<array{value:string, label:string}>
     */
    private function choices(string $html, string $name): array
    {
        $choices = [];
        foreach ($this->parser->selectOptions($html, $name) as $option) {
            if ($option['value'] === '') {
                continue;
            }
            $choices[] = ['value' => $option['value'], 'label' => $option['label']];
        }

        return $choices;
    }

    private function guard(Work1Response $response): Work1Response
    {
        if ($response->status >= 400) {
            throw new Work1Exception($this->parser->alert($response->body) ?? 'work1 ответил '.$response->status);
        }

        return $response;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $method, array $context = []): void
    {
        if (! function_exists('app')) {
            return;
        }

        try {
            if (! app()->bound('log')) {
                return;
            }
            \Illuminate\Support\Facades\Log::info('[CabinetClient.'.$method.']', $context);
        } catch (\Throwable) {
            // Unit tests build the client without the framework log binding.
        }
    }
}
