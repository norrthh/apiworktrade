<?php

namespace App\Services\Work1;

interface Work1Transport
{
    public function ensure(): void;

    public function submitOtp(string $otp): void;

    public function get(string $path): Work1Response;

    /**
     * @param  array<string, scalar|null>  $fields
     */
    public function post(string $path, array $fields, bool $ajax = false): Work1Response;
}
