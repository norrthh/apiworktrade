<?php

use App\Http\Middleware\RequireWork1Token;
use App\Services\Work1\MfaRequiredException;
use App\Services\Work1\Work1Exception;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'work1.token' => RequireWork1Token::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (MfaRequiredException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'authenticated' => false,
                    'mfa_required' => true,
                ], 428);
            }
        });
        $exceptions->render(function (Work1Exception $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => $exception->getMessage()], 422);
            }
        });
    })->create();
