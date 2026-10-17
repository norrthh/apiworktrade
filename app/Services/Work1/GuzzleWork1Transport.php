<?php

namespace App\Services\Work1;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleWork1Transport implements Work1Transport
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

        $page = $this->request('GET', '/admin/offers/index');
        if ($this->isMfa($page)) {
            throw new MfaRequiredException;
        }
        if ($this->isLogin($page)) {
            $this->login();

            return;
        }

        $this->ready = true;
    }

    public function submitOtp(string $otp): void
    {
        $page = $this->request('GET', '/admin/site/mfa-verify');
        if (! $this->isMfa($page)) {
            if ($this->isLogin($page)) {
                $this->login();
                $page = $this->request('GET', '/admin/site/mfa-verify');
            }
            if (! $this->isMfa($page)) {
                $this->ready = ! $this->isLogin($page);

                return;
            }
        }

        $posted = $this->request('POST', '/admin/site/mfa-verify', [
            'OtpForm[otp]' => $otp,
            'login-button' => '',
        ]);
        $landed = $this->follow($posted);
        if ($this->isMfa($landed)) {
            throw new Work1Exception('Код 2FA не принят');
        }
        if ($this->isLogin($landed)) {
            throw new Work1Exception('Сессия 2FA протухла, запроси действие ещё раз');
        }

        $this->ready = true;
    }

    public function get(string $path): Work1Response
    {
        $this->ensure();

        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields, bool $ajax = false): Work1Response
    {
        $this->ensure();

        return $this->request('POST', $path, $fields, $ajax);
    }

    private function login(): void
    {
        $password = (string) config('work1.password');
        if ($password === '') {
            throw new Work1Exception('В .env нет WORK1_PASSWORD');
        }

        $this->request('GET', '/admin/site/login');
        $posted = $this->request('POST', '/admin/site/login', [
            'LoginForm[username]' => (string) config('work1.username'),
            'LoginForm[password]' => $password,
            'LoginForm[rememberMe]' => '1',
            'login-button' => '',
        ]);
        if ($this->isMfa($posted)) {
            if ($posted->location !== null) {
                $this->request('GET', $posted->location);
            }
            $this->finishMfa();

            return;
        }
        $landed = $this->follow($posted);
        if ($this->isMfa($landed)) {
            $this->finishMfa();

            return;
        }
        if ($this->isLogin($landed)) {
            throw new Work1Exception('work1 не принял логин');
        }

        $this->ready = true;
    }

    private function finishMfa(): void
    {
        $secret = (string) config('work1.totp_secret');
        if ($secret === '') {
            throw new MfaRequiredException;
        }

        $posted = $this->request('POST', '/admin/site/mfa-verify', [
            'OtpForm[otp]' => Work1Totp::code($secret),
            'login-button' => '',
        ]);
        $landed = $this->follow($posted);
        if ($this->isMfa($posted) || $this->isMfa($landed)) {
            throw new Work1Exception('Код 2FA не принят');
        }
        if ($this->isLogin($landed)) {
            throw new Work1Exception('Сессия 2FA протухла, запроси действие ещё раз');
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
            $token = is_string($fields['_csrf-backend'] ?? null) && $fields['_csrf-backend'] !== ''
                ? (string) $fields['_csrf-backend']
                : $this->csrf;
            if ($token !== null) {
                $fields['_csrf-backend'] = $token;
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

        return $this->request('GET', $this->pathFromLocation($response->location));
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

    private function pathFromLocation(string $location): string
    {
        return $this->resolveLocation($location, '/');
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
     * Repeated keys for a multi-select: Methods[bank_ids][]=51&Methods[bank_ids][]=27.
     *
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
        return str_contains($response->body, 'LoginForm[username]')
            || str_contains((string) $response->location, '/admin/site/login');
    }

    private function isMfa(Work1Response $response): bool
    {
        return str_contains($response->body, 'OtpForm[otp]')
            || str_contains((string) $response->location, 'mfa-verify');
    }

    private function loadCookies(): CookieJar
    {
        $path = (string) config('work1.cookie_jar');
        if (! is_file($path)) {
            return new CookieJar;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return new CookieJar(false, is_array($decoded) ? $decoded : []);
    }

    private function storeCookies(): void
    {
        $path = (string) config('work1.cookie_jar');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($path, json_encode($this->cookies->toArray(), JSON_UNESCAPED_SLASHES));
    }
}
