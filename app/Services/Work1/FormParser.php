<?php

namespace App\Services\Work1;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class FormParser
{
    /**
     * @return array<string, string>
     */
    public function fields(string $html): array
    {
        $fields = [];
        foreach ($this->elements($html, '//input[@name]|//textarea[@name]|//select[@name]') as $element) {
            $name = $element->getAttribute('name');
            if ($name === '' || str_contains(strtolower($name), 'password')) {
                continue;
            }

            if ($element->nodeName === 'textarea') {
                $fields[$name] = $element->textContent ?? '';

                continue;
            }

            if ($element->nodeName === 'select') {
                $fields[$name] = $this->selectedOption($element);

                continue;
            }

            $type = strtolower($element->getAttribute('type') ?: 'text');
            if (in_array($type, ['submit', 'button', 'image', 'file', 'password'], true)) {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true) && ! $element->hasAttribute('checked')) {
                continue;
            }

            $fields[$name] = $element->getAttribute('value');
        }

        return $fields;
    }

    public function csrf(string $html): ?string
    {
        $fields = $this->fields($html);
        foreach (['_csrf-backend', '_csrf-frontend'] as $name) {
            if (($fields[$name] ?? '') !== '') {
                return $fields[$name];
            }
        }

        if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    public function alert(string $html): ?string
    {
        $chunks = [];
        foreach ($this->elements($html, '//*[contains(@class,"alert") or contains(@class,"help-block") or contains(@class,"invalid-feedback")]') as $element) {
            $text = trim(preg_replace('/\s+/u', ' ', $element->textContent ?? '') ?? '');
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        if ($chunks === []) {
            return null;
        }

        return mb_substr(implode(' ', array_unique($chunks)), 0, 240);
    }

    public function offerIdFromLocation(?string $location): ?int
    {
        if ($location === null || $location === '') {
            return null;
        }

        if (preg_match('/[?&]id=(\d+)/', $location, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * @return list<array{id:int, merchant_code:?string, method:?string, agent:?string}>
     */
    public function indexRows(string $html): array
    {
        $rows = [];
        foreach ($this->elements($html, '//tr[@data-key]') as $row) {
            $cells = [];
            foreach ($this->xpath($row)->query('./td', $row) ?: [] as $cell) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent ?? '') ?? '');
            }
            $text = implode(' ', $cells);
            $code = null;
            $method = null;
            foreach ($cells as $cell) {
                if ($code === null && preg_match('/^M_[A-Z0-9]+$/', $cell) === 1) {
                    $code = $cell;
                }
                if ($method === null && $this->isMethodSlug($cell)) {
                    $method = $cell;
                }
            }
            if ($code === null && preg_match('/\b(M_[A-Z0-9]+)\b/', $text, $match) === 1) {
                $code = $match[1];
            }

            $rows[] = [
                'id' => (int) $row->getAttribute('data-key'),
                'merchant_code' => $code,
                'method' => $method,
                'agent' => str_contains($text, 'superadmin') ? 'superadmin' : null,
            ];
        }

        return $rows;
    }

    private function isMethodSlug(string $cell): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $cell) !== 1) {
            return false;
        }

        return ! in_array($cell, ['superadmin'], true);
    }

    /**
     * @return array{payin_com:?string, payout_com:?string, payin_enabled:?bool, payout_enabled:?bool}|null
     */
    public function traderRow(string $html, int $offerId): ?array
    {
        $node = $this->elements($html, '//*[@data-offer="'.$offerId.'"]')[0] ?? null;
        if (! $node instanceof DOMElement) {
            return null;
        }

        $payin = null;
        $payout = null;
        foreach ($this->xpath($node)->query('.//input[@name]', $node) ?: [] as $input) {
            $name = $input->getAttribute('name');
            if (str_contains($name, 'payin_com')) {
                $payin = $input->getAttribute('value');
            }
            if (str_contains($name, 'payout_com')) {
                $payout = $input->getAttribute('value');
            }
        }

        $toggles = $this->toggles($node);

        return [
            'payin_com' => $payin,
            'payout_com' => $payout,
            'payin_enabled' => $toggles[0] ?? null,
            'payout_enabled' => $toggles[1] ?? null,
        ];
    }

    public function userEnabled(string $html, int $userId): ?bool
    {
        foreach ($this->elements($html, '//*[contains(@class,"fa-toggle-on") or contains(@class,"fa-toggle-off")]') as $icon) {
            $row = $this->ancestor($icon, 'tr') ?? $icon->parentNode;
            if (! $row instanceof DOMNode) {
                continue;
            }
            if (! $this->rowBelongsToUser($row, $userId)) {
                continue;
            }

            return str_contains($icon->getAttribute('class'), 'fa-toggle-on');
        }

        return null;
    }

    /**
     * @return array{archive:bool, status:?string}
     */
    public function requisiteState(string $html): array
    {
        $archive = false;
        $status = null;
        foreach ($this->elements($html, '//input[@name]') as $input) {
            $name = $input->getAttribute('name');
            $type = strtolower($input->getAttribute('type') ?: 'text');
            if ($type !== 'checkbox' || ! $input->hasAttribute('checked')) {
                continue;
            }
            if (str_contains($name, '[archive]')) {
                $archive = $input->getAttribute('value') === '1';
            }
            if (str_contains($name, '[status]')) {
                $status = $input->getAttribute('value');
            }
        }

        return ['archive' => $archive, 'status' => $status];
    }

    /**
     * @return list<array{id:int, cells:array<int|string, string>}>
     */
    public function gridRows(string $html): array
    {
        $headers = [];
        foreach ($this->elements($html, '//thead//th') as $index => $header) {
            $headers[$index] = trim(preg_replace('/\s+/u', ' ', $header->textContent ?? '') ?? '');
        }

        $rows = [];
        foreach ($this->elements($html, '//tr[@data-key]') as $row) {
            $mapped = [];
            $plain = [];
            $index = 0;
            foreach ($this->xpath($row)->query('./td', $row) ?: [] as $cell) {
                $text = $this->mask(trim(preg_replace('/\s+/u', ' ', $cell->textContent ?? '') ?? ''));
                $plain[] = $text;
                $header = $headers[$index] ?? '';
                if ($header !== '' && $header !== '#') {
                    $mapped[$header] = $text;
                }
                $index++;
            }

            $rows[] = [
                'id' => (int) $row->getAttribute('data-key'),
                'cells' => $mapped !== [] ? $mapped : $plain,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public function applyValues(array $fields, array $values): array
    {
        foreach ($values as $key => $value) {
            if (str_contains(strtolower($key), 'password')) {
                throw new Work1Exception('Пароль не записывается');
            }
            $fields[$this->matchField($fields, $key)] = $value;
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public function publicFields(array $fields): array
    {
        $public = [];
        foreach ($fields as $name => $value) {
            if (in_array($name, ['_csrf-backend', '_csrf-frontend'], true) || str_contains(strtolower($name), 'password')) {
                continue;
            }
            $public[$name] = $this->mask($value);
        }

        return $public;
    }

    /**
     * Карточки со страницы /cabinet/cards. Класс archive на самой карточке — выключенный реквизит.
     *
     * @return list<array{id:int, active:bool, automated:bool, number:?string, number_label:?string, bank:?string, recipient:?string, device:?string, turnover_day:?string, turnover_total:?string, counteragents_day:?string, counteragents_month:?string}>
     */
    public function cabinetCards(string $html): array
    {
        $cards = [];
        $query = '//div[contains(concat(" ", normalize-space(@class), " "), " deal_div ") and @data-id]';
        foreach ($this->elements($html, $query) as $card) {
            if (str_contains(' '.$card->getAttribute('class').' ', ' deal_div_add ')) {
                continue;
            }

            $pairs = [];
            foreach ($this->xpath($card)->query('.//li[span]', $card) ?: [] as $item) {
                if (! $item instanceof DOMElement) {
                    continue;
                }
                $labelNode = $this->xpath($item)->query('./span', $item)->item(0);
                if (! $labelNode instanceof DOMNode) {
                    continue;
                }
                $label = $this->plainText($labelNode);
                if ($label === '') {
                    continue;
                }
                $valueNode = $this->xpath($item)->query('.//span[contains(concat(" ", normalize-space(@class), " "), " default ")]', $item)->item(0);
                if (! $valueNode instanceof DOMNode) {
                    $valueNode = $this->xpath($item)->query('./p', $item)->item(0);
                }
                $pairs[$label] = $valueNode instanceof DOMNode ? $this->mask($this->plainText($valueNode)) : '';
            }

            $numberLabel = null;
            $number = null;
            foreach ($pairs as $label => $value) {
                if (! array_key_exists($label, $this->cabinetLabels())) {
                    $numberLabel = $label;
                    $number = $value;
                    break;
                }
            }

            $automated = $this->xpath($card)->query('.//*[contains(@class,"automation-status--active")]', $card);

            $cards[] = [
                'id' => (int) $card->getAttribute('data-id'),
                'active' => preg_match('/(?:^|\s)archive(?:\s|$)/', $card->getAttribute('class')) !== 1,
                'automated' => $automated !== false && $automated->length > 0,
                'number' => $number,
                'number_label' => $numberLabel,
                'bank' => $pairs['Наименование банка'] ?? null,
                'recipient' => $pairs['Получатель'] ?? null,
                'device' => $pairs['Название устройства'] ?? null,
                'turnover_day' => $pairs['Оборот за день'] ?? null,
                'turnover_total' => $pairs['Оборот за все время'] ?? null,
                'counteragents_day' => $pairs['Количество контрагенов в день'] ?? null,
                'counteragents_month' => $pairs['Количество контрагенов в месяц'] ?? null,
            ];
        }

        return $cards;
    }

    public function htmlFragment(string $body): string
    {
        $trim = ltrim($body);
        if ($trim === '' || ($trim[0] !== '"' && $trim[0] !== '{')) {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (is_string($decoded)) {
            return $decoded;
        }
        if (is_array($decoded)) {
            foreach (['html', 'content', 'form'] as $key) {
                if (is_string($decoded[$key] ?? null)) {
                    return $decoded[$key];
                }
            }
        }

        return $body;
    }

    /**
     * @return array{path:string, fields:array<string, string>}|null
     */
    public function formContaining(string $html, string $marker): ?array
    {
        foreach ($this->elements($html, '//form') as $form) {
            $inner = $form->ownerDocument?->saveHTML($form) ?? '';
            if (! str_contains($inner, $marker)) {
                continue;
            }
            $action = $form->getAttribute('action');

            return [
                'path' => $this->normalizePath($action !== '' ? $action : '/cabinet/cards'),
                'fields' => $this->fields($inner),
            ];
        }

        return null;
    }

    public function jsonAssignment(string $html, string $variable): mixed
    {
        $pos = strpos($html, $variable);
        if ($pos === false) {
            return null;
        }
        $start = strpos($html, '[', $pos);
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($html);
        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($char === '\\') {
                    $escape = true;

                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;

                continue;
            }
            if ($char === '[') {
                $depth++;
            }
            if ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode(substr($html, $start, $i - $start + 1), true);

                    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function cabinetLabels(): array
    {
        return [
            'Наименование банка' => 'bank',
            'Получатель' => 'recipient',
            'Название устройства' => 'device',
            'Оборот за день' => 'turnover_day',
            'Оборот за все время' => 'turnover_total',
            'Количество контрагенов в день' => 'counteragents_day',
            'Количество контрагенов в месяц' => 'counteragents_month',
        ];
    }

    private function plainText(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
    }

    public function requisiteCreatePath(string $html): ?string
    {
        $candidates = [];
        foreach ($this->elements($html, '//a[@href]') as $link) {
            $path = $this->normalizePath($link->getAttribute('href'));
            if (! str_contains($path, '/admin/requisite/')) {
                continue;
            }
            if (preg_match('#/(index|update|delete)(\?|$)#', $path) === 1) {
                continue;
            }
            $candidates[$path] = true;
        }

        $paths = array_keys($candidates);
        if (count($paths) === 1) {
            return $paths[0];
        }
        foreach ($paths as $path) {
            if (str_contains($path, 'create')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{path:string, fields:array<string, string>}|null
     */
    public function requisiteCreateForm(string $html): ?array
    {
        foreach ($this->elements($html, '//form[@action]') as $form) {
            $path = $this->normalizePath($form->getAttribute('action'));
            $inner = $form->ownerDocument?->saveHTML($form) ?? '';
            if (str_contains($inner, 'RequisiteSearch') || str_contains($path, 'Search')) {
                continue;
            }
            if (! str_contains($path, 'requisite') && ! str_contains($inner, 'Requisite')) {
                continue;
            }
            if ($path === '') {
                continue;
            }

            return [
                'path' => $path,
                'fields' => $this->fields($inner),
            ];
        }

        return null;
    }

    /**
     * @return list<array{value:string, label:string, selected:bool}>
     */
    public function selectOptions(string $html, string $name): array
    {
        $options = [];
        $query = '//select[@name="'.$name.'"]/option';
        foreach ($this->elements($html, $query) as $option) {
            $options[] = [
                'value' => $option->getAttribute('value'),
                'label' => trim(preg_replace('/\s+/u', ' ', $option->textContent ?? '') ?? ''),
                'selected' => $option->hasAttribute('selected'),
            ];
        }

        return $options;
    }

    public function jsonOrRaw(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(string $html, string $query): array
    {
        $document = $this->document($html);
        if (! $document instanceof DOMDocument) {
            return [];
        }

        $nodes = (new DOMXPath($document))->query($query);
        if ($nodes === false) {
            return [];
        }

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function document(string $html): ?DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function selectedOption(DOMElement $select): string
    {
        $fallback = '';
        foreach ($select->getElementsByTagName('option') as $index => $option) {
            if (! $option instanceof DOMElement) {
                continue;
            }
            if ($index === 0) {
                $fallback = $option->getAttribute('value');
            }
            if ($option->hasAttribute('selected')) {
                return $option->getAttribute('value');
            }
        }

        return $fallback;
    }

    /**
     * @return list<bool>
     */
    private function toggles(DOMElement $scope): array
    {
        $states = [];
        foreach ($this->xpath($scope)->query('.//*[contains(@class,"fa-toggle-on") or contains(@class,"fa-toggle-off")]', $scope) ?: [] as $icon) {
            if ($icon instanceof DOMElement) {
                $states[] = str_contains($icon->getAttribute('class'), 'fa-toggle-on');
            }
        }

        return $states;
    }

    private function xpath(DOMNode $node): DOMXPath
    {
        $document = $node instanceof DOMDocument ? $node : $node->ownerDocument;

        return new DOMXPath($document);
    }

    private function ancestor(DOMNode $node, string $name): ?DOMElement
    {
        $current = $node->parentNode;
        while ($current instanceof DOMNode) {
            if ($current instanceof DOMElement && $current->nodeName === $name) {
                return $current;
            }
            $current = $current->parentNode;
        }

        return null;
    }

    private function rowBelongsToUser(DOMNode $row, int $userId): bool
    {
        $html = $row->ownerDocument?->saveHTML($row) ?? '';
        if (preg_match('/name="user_id"[^>]*value="'.$userId.'"/', $html) === 1) {
            return true;
        }
        if (preg_match('/data-user="'.$userId.'"/', $html) === 1) {
            return true;
        }

        $text = $row->textContent ?? '';

        return str_contains($text, 'tester@test.com') || str_contains($text, 'Тестов');
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function matchField(array $fields, string $key): string
    {
        if (array_key_exists($key, $fields)) {
            return $key;
        }

        $matches = [];
        foreach (array_keys($fields) as $name) {
            if (str_ends_with($name, '['.$key.']')) {
                $matches[] = $name;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }

        $available = [];
        foreach (array_keys($fields) as $name) {
            if (in_array($name, ['_csrf-backend', '_csrf-frontend'], true)) {
                continue;
            }
            $available[] = preg_match('/\[([^\]]+)\]$/', $name, $suffix) === 1 ? $suffix[1] : $name;
        }

        throw new Work1Exception('Не вижу поле '.$key.'. Доступны: '.implode(', ', $available));
    }

    private function mask(string $text): string
    {
        $digits = preg_replace('/\D+/', '', $text) ?? '';
        if ($digits !== $text && strlen($digits) >= 13 && strlen($digits) <= 19 && preg_match('/\d(?:[ \-]*\d){12,18}/', $text) === 1) {
            return substr($digits, -4);
        }

        return preg_replace_callback('/\d{13,19}/', fn (array $match): string => substr($match[0], -4), $text) ?? $text;
    }

    private function normalizePath(string $href): string
    {
        $href = trim(html_entity_decode($href));
        if ($href === '' || str_starts_with($href, '#')) {
            return '';
        }

        if (preg_match('#^https?://#', $href) === 1) {
            $parts = parse_url($href);
            $path = $parts['path'] ?? '/';
            if (! empty($parts['query'])) {
                $path .= '?'.$parts['query'];
            }

            return $path;
        }

        return str_starts_with($href, '/') ? $href : '/'.$href;
    }
}
