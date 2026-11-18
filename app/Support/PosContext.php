<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use App\Models\CashierShift;
use App\Models\EmployeeShiftCheckin;
use App\Models\Outlet;
use App\Models\PosTerminal;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Request-scoped POS & Sub-Distributor session context
 * (who / which company / which active outlet / which active warehouse / which terminal / which shift).
 *
 * Registered as a *scoped* singleton so it is reset between requests and queue jobs.
 * It feeds the tenant global scopes, the audit logger and the domain services.
 */
final class PosContext
{
    private ?User $user = null;

    private ?Outlet $outlet = null;

    private ?Warehouse $warehouse = null;

    private ?PosTerminal $terminal = null;

    private ?CashierShift $shift = null;

    private ?EmployeeShiftCheckin $employeeShiftCheckin = null;

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function setSession(Outlet $outlet, ?PosTerminal $terminal = null, ?Warehouse $warehouse = null): void
    {
        $this->outlet = $outlet;
        $this->terminal = $terminal;
        $this->warehouse = $warehouse;
    }

    public function setWarehouse(?Warehouse $warehouse): void
    {
        $this->warehouse = $warehouse;
    }

    public function setShift(?CashierShift $shift): void
    {
        $this->shift = $shift;
    }

    public function setEmployeeShiftCheckin(?EmployeeShiftCheckin $checkin): void
    {
        $this->employeeShiftCheckin = $checkin;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function outlet(): ?Outlet
    {
        return $this->outlet;
    }

    public function warehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    public function terminal(): ?PosTerminal
    {
        return $this->terminal;
    }

    public function shift(): ?CashierShift
    {
        return $this->shift;
    }

    public function employeeShiftCheckin(): ?EmployeeShiftCheckin
    {
        return $this->employeeShiftCheckin;
    }

    public function outletId(): ?int
    {
        return $this->outlet?->id;
    }

    public function warehouseId(): ?int
    {
        return $this->warehouse?->id;
    }

    public function terminalId(): ?int
    {
        return $this->terminal?->id;
    }

    public function organizationId(): ?int
    {
        return $this->user?->organization_id;
    }

    public function requireUser(): User
    {
        return $this->user ?? throw new BusinessRuleException('UNAUTHENTICATED', 'No authenticated user in context.', 401);
    }

    public function requireOutlet(): Outlet
    {
        return $this->outlet ?? throw new BusinessRuleException('OUTLET_CONTEXT_REQUIRED', 'Select an outlet first.', 409);
    }

    public function requireWarehouse(): Warehouse
    {
        if ($this->warehouse !== null) {
            return $this->warehouse;
        }

        $outlet = $this->requireOutlet();
        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();

        if ($warehouse === null) {
            throw new BusinessRuleException('WAREHOUSE_CONTEXT_REQUIRED', 'Active outlet does not have an assigned branch warehouse.', 409);
        }

        return $this->warehouse = $warehouse;
    }

    public function requireTerminal(): PosTerminal
    {
        if ($this->terminal !== null) {
            return $this->terminal;
        }

        $outlet = $this->requireOutlet();
        $terminal = PosTerminal::query()
            ->where('outlet_id', $outlet->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($terminal === null) {
            throw new BusinessRuleException('OUTLET_CONTEXT_REQUIRED', 'Select an outlet and terminal first.', 409);
        }

        return $this->terminal = $terminal;
    }

    public function requireShift(): CashierShift
    {
        return $this->shift ?? throw new BusinessRuleException('SHIFT_NOT_OPEN', 'Open a cashier shift before performing this action.', 409);
    }

    /**
     * Outlet IDs the current actor may see, or null when unrestricted
     * (system processes and super admins outside an outlet workspace).
     *
     * @return list<int>|null
     */
    public function scopedOutletIds(): ?array
    {
        if ($this->user === null) {
            return null;
        }

        // Strict isolation: once an Outlet Workspace is active, only that outlet is visible
        // across all outlet-scoped models (even for Super Admin).
        if ($this->outlet !== null) {
            return [$this->outlet->id];
        }

        if ($this->user->isSuperAdmin()) {
            return null;
        }

        return $this->user->accessibleOutletIds();
    }
}
