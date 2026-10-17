<?php

namespace App\Services\Work1;

interface CabinetTransport
{
    public function ensure(): void;

    public function get(string $path): Work1Response;

    /**
     * @param  array<string, scalar|list<scalar>|null>  $fields
     */
    public function post(string $path, array $fields, bool $ajax = false): Work1Response;
}
