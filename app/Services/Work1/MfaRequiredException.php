<?php

namespace App\Services\Work1;

class MfaRequiredException extends Work1Exception
{
    public function __construct()
    {
        parent::__construct('Нужен код 2FA');
    }
}
