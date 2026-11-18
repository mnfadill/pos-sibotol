<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleException;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\PersonalAccessToken;
use App\Models\PosTerminal;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Support\PosContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hydrates PosContext from the authenticated user and active Sanctum token
 * and enforces strict backend outlet access validation against manipulation.
 */
final class ResolvePosContextMiddleware
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            throw new BusinessRuleException('USER_INACTIVE', 'Your user account has been deactivated.', 403);
        }

        $this->context->setUser($user);

        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        $tokenOutletId = $token instanceof PersonalAccessToken ? $token->outlet_id : null;
        $outletId = $tokenOutletId;
        $warehouseId = $token instanceof PersonalAccessToken ? $token->warehouse_id : null;
        $terminalId = $token instanceof PersonalAccessToken ? $token->pos_terminal_id : null;

        // 1. Validate any explicit X-Outlet-Id header
        if ($request->hasHeader('X-Outlet-Id')) {
            $headerOutletId = (int) $request->header('X-Outlet-Id');
            if ($headerOutletId > 0) {
                if (! $user->canAccessOutlet($headerOutletId)) {
                    $this->audit->record(
                        AuditAction::ACCESS_DENIED,
                        'Outlet',
                        newValues: ['attempted_outlet_id' => $headerOutletId, 'via' => 'X-Outlet-Id'],
                        actor: $user,
                    );

                    throw new BusinessRuleException(
                        'OUTLET_ACCESS_DENIED',
                        'You are not assigned to the requested outlet.',
                        403,
                    );
                }
                $outletId = $headerOutletId;
            }
        }

        // 2. Prevent outlet_id manipulation in query/body on non-context-switching / operational POS routes.
        // Multi-outlet management, master data, pricing rules, and audit/reporting routes may target any
        // outlet that the authenticated user is authorized to access (validated via canAccessOutlet below).
        $isContextSwitchRoute = $request->is(
            'api/v1/auth/select-outlet',
            'api/v1/auth/switch-outlet',
            'api/v1/auth/select-session',
            'api/v1/auth/session',
            'api/v1/auth/outlets/*/favorite',
            'api/v1/company/*',
            'api/v1/pricing-rules*',
            'api/v1/master-data/*',
            'api/v1/products*',
            'api/v1/stock-ledger*',
            'api/v1/batches*',
            'api/v1/tiers*',
            'api/v1/audit-logs*',
            'api/v1/activity-logs*',
            'api/v1/user-management/*',
            'api/v1/shifts/manage*',
            'api/v1/employee-shifts/*',
            'api/v1/procurement*',
            'api/v1/warehouses*',
        );

        if ($request->has('outlet_id') && $request->input('outlet_id') !== null && $request->input('outlet_id') !== '') {
            $inputOutletId = (int) $request->input('outlet_id');
            if ($inputOutletId > 0) {
                if (! $user->canAccessOutlet($inputOutletId)) {
                    $this->audit->record(
                        AuditAction::ACCESS_DENIED,
                        'Outlet',
                        newValues: ['attempted_outlet_id' => $inputOutletId, 'via' => 'request_payload'],
                        actor: $user,
                    );

                    throw new BusinessRuleException(
                        'OUTLET_ACCESS_DENIED',
                        'You do not have permission to access or transact in the requested outlet.',
                        403,
                    );
                }

                // If user is bound to an active outlet and attempts to pass a different outlet_id on an operational route
                if (! $isContextSwitchRoute && $outletId !== null && $inputOutletId !== (int) $outletId) {
                    $this->audit->record(
                        AuditAction::ACCESS_DENIED,
                        'Outlet',
                        newValues: [
                            'active_outlet_id' => $outletId,
                            'attempted_outlet_id' => $inputOutletId,
                            'reason' => 'cross_outlet_payload_manipulation',
                        ],
                        actor: $user,
                    );

                    throw new BusinessRuleException(
                        'OUTLET_CONTEXT_MISMATCH',
                        'Cannot execute transaction for a different outlet than your active outlet workspace. Switch outlet first.',
                        403,
                    );
                }
            }
        }

        if ($request->hasHeader('X-Pos-Terminal-Id')) {
            $headerTerminalId = (int) $request->header('X-Pos-Terminal-Id');
            if ($headerTerminalId > 0) {
                $terminalId = $headerTerminalId;
            }
        }

        if ($outletId !== null) {
            if (! $user->canAccessOutlet((int) $outletId)) {
                throw new BusinessRuleException(
                    'OUTLET_ACCESS_DENIED',
                    'You are not assigned to the requested outlet.',
                    403,
                );
            }

            $outlet = Outlet::query()->withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)
                ->where('is_active', true)
                ->find($outletId);

            if ($outlet !== null) {
                // Resolve Branch Warehouse for this outlet
                $warehouse = null;
                if ($warehouseId !== null) {
                    $warehouse = Warehouse::query()->withoutGlobalScopes()
                        ->where('outlet_id', $outlet->id)
                        ->where('is_active', true)
                        ->find($warehouseId);
                }
                if ($warehouse === null) {
                    $warehouse = Warehouse::query()->withoutGlobalScopes()
                        ->where('outlet_id', $outlet->id)
                        ->where('is_active', true)
                        ->orderByDesc('is_default')
                        ->first();
                }

                // Resolve POS Terminal (explicit or default active terminal for the outlet)
                $terminal = null;
                if ($terminalId !== null) {
                    $terminal = PosTerminal::query()
                        ->where('outlet_id', $outlet->id)
                        ->where('is_active', true)
                        ->find($terminalId);
                }
                if ($terminal === null) {
                    $terminal = PosTerminal::query()
                        ->where('outlet_id', $outlet->id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->first();
                }

                $this->context->setSession($outlet, $terminal, $warehouse);

                if ($terminal !== null) {
                    $openShift = CashierShift::query()->withoutGlobalScopes()
                        ->where('outlet_id', $outlet->id)
                        ->where('status', 'open')
                        ->where(static function ($q) use ($terminal, $user): void {
                            $q->where('pos_terminal_id', $terminal->id)
                                ->orWhere('user_id', $user->id);
                        })
                        ->orderByDesc('opened_at')
                        ->first();

                    $this->context->setShift($openShift);
                }

                // Resolve active operational employee shift check-in
                $activeEmployeeCheckin = \App\Models\EmployeeShiftCheckin::query()
                    ->with(['shift'])
                    ->where('user_id', $user->id)
                    ->where('outlet_id', $outlet->id)
                    ->where('status', 'active')
                    ->latest('checked_in_at')
                    ->first();

                $this->context->setEmployeeShiftCheckin($activeEmployeeCheckin);
            }
        }

        return $next($request);
    }
}
