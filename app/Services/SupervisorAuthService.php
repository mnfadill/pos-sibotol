<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PosContext;

/**
 * Verifies Supervisor / Outlet Manager / Super Admin PIN authorization for
 * privileged POS operations (manual discount overrides, price overrides,
 * transaction voids, refunds, manual stock adjustments, and stock opname).
 */
final class SupervisorAuthService
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function authorizeByPin(
        string $pin,
        string $reasonContext,
        ?int $outletId = null,
        Permission $requiredPermission = Permission::OverridesAuthorize,
    ): User {
        $pin = trim($pin);
        $targetOutletId = $outletId ?? $this->context->outletId();
        $organizationId = $this->context->organizationId();

        if ($pin === '') {
            throw new BusinessRuleException(
                'SUPERVISOR_PIN_REQUIRED',
                'Supervisor PIN is required to authorize this action.',
                422,
            );
        }

        // Candidate authorizers: active users in the organization who hold supervisor/manager/super_admin role
        $candidates = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->with('roles.permissions')
            ->get()
            ->filter(static function (User $candidate) use ($targetOutletId, $requiredPermission): bool {
                $isSupervisorOrHigher = $candidate->hasRole(
                    RoleName::SuperAdmin,
                    RoleName::OutletManager,
                    RoleName::Supervisor,
                );

                if (! $isSupervisorOrHigher || ! $candidate->hasPermission($requiredPermission)) {
                    return false;
                }

                return $targetOutletId === null || $candidate->canAccessOutlet($targetOutletId);
            });

        foreach ($candidates as $supervisor) {
            if ($supervisor->verifyPin($pin)) {
                return $supervisor;
            }
        }

        $this->audit->record(
            AuditAction::SUPERVISOR_AUTH_FAILED,
            'SupervisorOverride',
            metadata: [
                'reason_context' => $reasonContext,
                'required_permission' => $requiredPermission->value,
            ],
        );

        throw new BusinessRuleException(
            'INVALID_SUPERVISOR_PIN',
            'Invalid Supervisor PIN or insufficient privileges for this outlet.',
            403,
        );
    }
}

