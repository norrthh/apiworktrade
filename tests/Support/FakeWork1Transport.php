<?php

namespace Tests\Support;

use App\Services\Work1\Work1Response;
use App\Services\Work1\Work1Transport;

final class FakeWork1Transport implements Work1Transport
{
    /** @var list<string> */
    public array $gets = [];

    /** @var list<array{path:string, fields:array<string, scalar|null>, ajax:bool}> */
    public array $posts = [];

    /** @var list<array{0:string, 1:Work1Response}> */
    public array $map = [];

    public function ensure(): void {}

    public function submitOtp(string $otp): void {}

    public function get(string $path): Work1Response
    {
        $this->gets[] = $path;

        return $this->match($path);
    }

    public function post(string $path, array $fields, bool $ajax = false): Work1Response
    {
        $this->posts[] = ['path' => $path, 'fields' => $fields, 'ajax' => $ajax];

        return $this->match($path);
    }

    public function respond(string $prefix, string $body, int $status = 200, ?string $location = null): void
    {
        $this->map[] = [$prefix, new Work1Response($status, $body, $location)];
    }

    private function match(string $path): Work1Response
    {
        foreach ($this->map as [$prefix, $response]) {
            if (str_starts_with($path, $prefix)) {
                return $response;
            }
        }

        return new Work1Response(200, '');
    }
}
