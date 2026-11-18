<?php

declare(strict_types=1);

use App\Http\Middleware\AuditLoggerMiddleware;
use App\Http\Middleware\EnforceIdempotencyMiddleware;
use App\Http\Middleware\RequireOpenShiftMiddleware;
use App\Http\Middleware\RequirePermissionMiddleware;
use App\Http\Middleware\RequireTerminalSessionMiddleware;
use App\Http\Middleware\ResolvePosContextMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            AuditLoggerMiddleware::class,
        ]);

        $middleware->alias([
            'pos.context' => ResolvePosContextMiddleware::class,
            'pos.terminal' => RequireTerminalSessionMiddleware::class,
            'pos.shift' => RequireOpenShiftMiddleware::class,
            'pos.idempotent' => EnforceIdempotencyMiddleware::class,
            'permission' => RequirePermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
