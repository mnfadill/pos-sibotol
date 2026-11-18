<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PosContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks checkout and cash drawer operations until the cashier opens a shift
 * with an opening cash balance.
 */
final class RequireOpenShiftMiddleware
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->requireOutlet();
        $this->context->requireTerminal();
        $this->context->requireShift();

        return $next($request);
    }
}

