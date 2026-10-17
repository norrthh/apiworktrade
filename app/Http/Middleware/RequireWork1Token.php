<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWork1Token
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('work1.api_token');
        $given = (string) $request->bearerToken();
        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
