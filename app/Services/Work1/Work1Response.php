<?php

namespace App\Services\Work1;

final class Work1Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $location = null,
    ) {}
}
