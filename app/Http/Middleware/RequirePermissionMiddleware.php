<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level RBAC guard. Usage: ->middleware('permission:users.view,users.manage')
 * Access is granted when the user holds ANY of the listed permissions.
 * Super Admin implicitly holds every permission.
 */
final class RequirePermissionMiddleware
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User) {
            throw new BusinessRuleException('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        $this->audit->record(
            AuditAction::ACCESS_DENIED,
            'Permission',
            newValues: [
                'required_any' => $permissions,
                'route' => $request->path(),
                'method' => $request->method(),
            ],
            actor: $user,
        );

        throw new BusinessRuleException(
            'PERMISSION_DENIED',
            'Anda tidak memiliki izin untuk mengakses fitur ini.',
            403,
            ['required_any' => $permissions],
        );
    }
}
