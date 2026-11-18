<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PosContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces mandatory Outlet and POS Terminal selection on session-bound routes.
 */
final class RequireTerminalSessionMiddleware
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->requireOutlet();
        $this->context->requireTerminal();

        return $next($request);
    }
}

