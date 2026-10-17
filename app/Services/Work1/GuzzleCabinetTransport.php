<?php

namespace App\Services\Work1;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleCabinetTransport implements CabinetTransport
{
    private CookieJar $cookies;

    private ?string $csrf = null;

    private bool $ready = false;

    public function __construct(
        private readonly FormParser $parser,
        private readonly ?Client $client = null,
    ) {
        $this->cookies = $this->loadCookies();
    }

    public function ensure(): void
    {
        if ($this->ready) {
            return;
        }

        $page = $this->request('GET', '/cabinet/main');
        if ($this->isLogin($page)) {
            $this->login();

            return;
        }

        $this->ready = true;
    }

    public function get(string $path): Work1Response
    {
        $this->ensure();
        $response = $this->request('GET', $path);
        if ($this->isLogin($response)) {
            $this->ready = false;
            $this->login();
            $response = $this->request('GET', $path);
        }
        if ($this->isLogin($response)) {
            throw new Work1Exception('Сессия трейдера протухла');
        }

        return $response;
    }

    public function post(string $path, array $fields, bool $ajax = false): Work1Response
    {
        $this->ensure();
        $response = $this->request('POST', $path, $fields, $ajax);
        if ($this->isLogin($response)) {
            $this->ready = false;
            $this->login();
            $response = $this->request('POST', $path, $fields, $ajax);
        }
        if ($this->isLogin($response)) {
            throw new Work1Exception('Сессия трейдера протухла');
        }

        return $response;
    }

    private function login(): void
    {
        $password = (string) config('work1.trader_password');
        if ($password === '') {
            throw new Work1Exception('В .env нет WORK1_TRADER_PASSWORD');
        }

        $this->request('GET', '/site/login');
        $posted = $this->request('POST', '/site/login', [
            'LoginForm[email]' => (string) config('work1.trader_email'),
            'LoginForm[password]' => $password,
            'LoginForm[rememberMe]' => '1',
            'login-button' => '',
        ]);
        $landed = $this->follow($posted);
        $rejected = ($this->isLogin($posted) && $posted->location === null) || $this->isLogin($landed);
        if ($rejected) {
            throw new Work1Exception('work1 не принял логин трейдера');
        }

        $this->ready = true;
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $fields
     */
    private function request(string $method, string $path, array $fields = [], bool $ajax = false): Work1Response
    {
        $options = [
            'cookies' => $this->cookies,
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/json',
            ],
        ];

        if ($method === 'POST') {
            $token = is_string($fields['_csrf-frontend'] ?? null) && $fields['_csrf-frontend'] !== ''
                ? (string) $fields['_csrf-frontend']
                : $this->csrf;
            if ($token !== null) {
                $fields['_csrf-frontend'] = $token;
                $options['headers']['X-CSRF-Token'] = $token;
            }
            if ($ajax) {
                $options['headers']['X-Requested-With'] = 'XMLHttpRequest';
            }
            if ($this->hasList($fields)) {
                $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                $options['body'] = $this->encodeForm($fields);
            } else {
                $options['form_params'] = $fields;
            }
        }

        try {
            $response = $this->http()->request($method, $this->url($path), $options);
        } catch (GuzzleException $exception) {
            throw new Work1Exception('work1 недоступен: '.$exception->getMessage());
        }

        $body = (string) $response->getBody();
        $token = $this->parser->csrf($body);
        if ($token !== null) {
            $this->csrf = $token;
        }
        $this->storeCookies();

        $location = $response->getHeaderLine('Location');

        return new Work1Response(
            $response->getStatusCode(),
            $body,
            $location !== '' ? $this->resolveLocation($location, $path) : null,
        );
    }

    private function follow(Work1Response $response): Work1Response
    {
        if ($response->location === null || $response->status < 300 || $response->status >= 400) {
            return $response;
        }

        return $this->request('GET', $response->location);
    }

    private function http(): Client
    {
        return $this->client ?? new Client;
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('work1.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function resolveLocation(string $location, string $current): string
    {
        if (preg_match('#^https?://#', $location) === 1) {
            $location = preg_replace('#^http://#', 'https://', $location) ?? $location;
            $parts = parse_url($location);
            $path = $parts['path'] ?? '/';
            if (! empty($parts['query'])) {
                $path .= '?'.$parts['query'];
            }

            return $path;
        }

        if (str_starts_with($location, '/')) {
            return $location;
        }

        $base = parse_url($current, PHP_URL_PATH) ?: '/';
        $directory = preg_replace('#/[^/]*$#', '/', $base) ?: '/';

        return $directory.$location;
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $fields
     */
    private function hasList(array $fields): bool
    {
        foreach ($fields as $value) {
            if (is_array($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $fields
     */
    private function encodeForm(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $name = str_ends_with((string) $key, '[]') ? (string) $key : $key.'[]';
                foreach ($value as $item) {
                    $pairs[] = urlencode($name).'='.urlencode((string) $item);
                }

                continue;
            }
            $pairs[] = urlencode((string) $key).'='.urlencode((string) ($value ?? ''));
        }

        return implode('&', $pairs);
    }

    private function isLogin(Work1Response $response): bool
    {
        return str_contains($response->body, 'LoginForm[email]')
            || str_contains((string) $response->location, '/site/login');
    }

    private function loadCookies(): CookieJar
    {
        $path = (string) config('work1.trader_cookie_jar');
        if ($path === '' || ! is_file($path)) {
            return new CookieJar;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return new CookieJar(false, is_array($decoded) ? $decoded : []);
    }

    private function storeCookies(): void
    {
        $path = (string) config('work1.trader_cookie_jar');
        if ($path === '') {
            return;
        }
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($path, json_encode($this->cookies->toArray(), JSON_UNESCAPED_SLASHES));
    }
}
