<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\ShiftStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Services\Audit\AuditLogger;
use App\Services\Outbox\OutboxService;
use App\Support\Money;
use App\Support\PosContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ShiftService
{
    public function __construct(
        private readonly PosContext $context,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly SupervisorAuthService $supervisorAuth,
    ) {}

    public function openShift(
        string|int|float $openingCash,
        ?string $notes = null,
        ?string $openedAt = null,
    ): CashierShift {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $terminal = $this->context->requireTerminal();

        $openingMinor = Money::toMinor($openingCash);
        if ($openingMinor < 0) {
            throw new BusinessRuleException('INVALID_OPENING_CASH', 'Opening cash balance cannot be negative.', 422);
        }

        return DB::transaction(function () use ($user, $outlet, $terminal, $openingMinor, $notes, $openedAt): CashierShift {
            $existing = CashierShift::query()->withoutGlobalScopes()
                ->where('status', ShiftStatus::Open->value)
                ->where(static function ($q) use ($terminal, $user): void {
                    $q->where('pos_terminal_id', $terminal->id)
                        ->orWhere('user_id', $user->id);
                })
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new BusinessRuleException(
                    'SHIFT_ALREADY_OPEN',
                    sprintf('An open shift (%s) already exists for this cashier or terminal.', $existing->shift_number),
                    409,
                );
            }

            $shiftNumber = $this->sequences->next($outlet, 'SHF');
            $openingDecimal = Money::toDecimal($openingMinor);

            $resolvedOpenedAt = CarbonImmutable::now();
            if ($openedAt !== null && trim($openedAt) !== '') {
                try {
                    $parsed = CarbonImmutable::parse(trim($openedAt));
                    if (strlen(trim($openedAt)) <= 10) {
                        $parsed = $parsed->setTime(
                            (int) now()->format('H'),
                            (int) now()->format('i'),
                            (int) now()->format('s'),
                        );
                    }
                    $resolvedOpenedAt = $parsed;
                } catch (\Throwable) {
                    $resolvedOpenedAt = CarbonImmutable::now();
                }
            }

            $shift = CashierShift::query()->create([
                'outlet_id' => $outlet->id,
                'pos_terminal_id' => $terminal->id,
                'user_id' => $user->id,
                'shift_number' => $shiftNumber,
                'status' => ShiftStatus::Open,
                'opening_cash' => $openingDecimal,
                'opening_notes' => $notes,
                'opened_at' => $resolvedOpenedAt,
            ]);

            $this->context->setShift($shift);

            $this->audit->record(
                AuditAction::SHIFT_OPENED,
                $shift,
                oldValues: null,
                newValues: [
                    'shift_number' => $shift->shift_number,
                    'opening_cash' => $shift->opening_cash,
                    'opening_notes' => $shift->opening_notes,
                    'opened_at' => $shift->opened_at->toIso8601String(),
                ],
            );

            $this->outbox->record('shift.opened', $shift, $shift->uuid, [
                'shift_number' => $shift->shift_number,
                'cashier_id' => $user->id,
                'cashier_name' => $user->name,
                'pos_terminal_id' => $terminal->id,
                'opening_cash' => $shift->opening_cash,
                'opened_at' => $shift->opened_at->toIso8601String(),
            ]);

            return $shift->load(['cashier', 'terminal']);
        });
    }

    public function recordCashMovement(
        CashMovementType $type,
        string|int|float $amount,
        string $reason,
        ?string $notes = null,
        ?string $supervisorPin = null,
    ): CashMovement {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $terminal = $this->context->requireTerminal();
        $shift = $this->context->requireShift();

        $amountMinor = Money::toMinor($amount);
        if ($amountMinor <= 0) {
            throw new BusinessRuleException('INVALID_CASH_MOVEMENT_AMOUNT', 'Cash movement amount must be greater than zero.', 422);
        }

        return DB::transaction(function () use ($user, $outlet, $terminal, $shift, $type, $amountMinor, $reason, $notes, $supervisorPin): CashMovement {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);
            if (! $lockedShift->isOpen()) {
                throw new BusinessRuleException('SHIFT_NOT_OPEN', 'Cannot record cash movement on a closed shift.', 409);
            }

            $authorizedBy = null;
            if ($supervisorPin !== null && trim($supervisorPin) !== '') {
                $supervisor = $this->supervisorAuth->authorizeByPin(
                    $supervisorPin,
                    sprintf('Cash %s (%s)', $type->value, $reason),
                    $outlet->id,
                    Permission::OverridesAuthorize,
                );
                $authorizedBy = $supervisor->id;
            }

            // Prevent cash drawer from going negative on paid_out
            if ($type === CashMovementType::PaidOut) {
                $live = $this->calculateMetrics($lockedShift);
                if ($amountMinor > $live['expected_cash_minor']) {
                    throw new BusinessRuleException(
                        'INSUFFICIENT_DRAWER_CASH',
                        sprintf(
                            'Paid-Out amount (%s) exceeds current expected drawer cash (%s).',
                            Money::toDecimal($amountMinor),
                            Money::toDecimal($live['expected_cash_minor']),
                        ),
                        422,
                    );
                }
            }

            $movement = CashMovement::query()->create([
                'cashier_shift_id' => $lockedShift->id,
                'outlet_id' => $outlet->id,
                'pos_terminal_id' => $terminal->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => Money::toDecimal($amountMinor),
                'reason' => $reason,
                'notes' => $notes,
                'authorized_by' => $authorizedBy,
            ]);

            $this->audit->record(
                $type === CashMovementType::PaidIn ? AuditAction::CASH_PAID_IN : AuditAction::CASH_PAID_OUT,
                $movement,
                oldValues: null,
                newValues: [
                    'shift_number' => $lockedShift->shift_number,
                    'type' => $type->value,
                    'amount' => $movement->amount,
                    'reason' => $movement->reason,
                    'notes' => $movement->notes,
                    'authorized_by' => $authorizedBy,
                ],
            );

            return $movement->load('user');
        });
    }

    /**
     * Real-time cash drawer & shift reconciliation figures.
     *
     * @return array<string, mixed>
     */
    public function calculateLiveSummary(CashierShift $shift): array
    {
        $metrics = $this->calculateMetrics($shift);

        return [
            'shift_id' => $shift->id,
            'shift_number' => $shift->shift_number,
            'status' => $shift->status->value,
            'opening_cash' => Money::toDecimal($metrics['opening_cash_minor']),
            'total_sales' => Money::toDecimal($metrics['total_sales_minor']),
            'total_cash_sales' => Money::toDecimal($metrics['cash_sales_minor']),
            'total_non_cash_sales' => Money::toDecimal($metrics['non_cash_sales_minor']),
            'total_paid_in' => Money::toDecimal($metrics['paid_in_minor']),
            'total_paid_out' => Money::toDecimal($metrics['paid_out_minor']),
            'total_cash_refunds' => Money::toDecimal($metrics['cash_refunds_minor']),
            'expected_cash' => Money::toDecimal($metrics['expected_cash_minor']),
            'actual_cash' => $shift->actual_cash,
            'cash_difference' => $shift->cash_difference,
            'transaction_count' => $metrics['transaction_count'],
            'breakdown_by_method' => $metrics['breakdown_by_method'],
        ];
    }

    public function closeShift(
        CashierShift $shift,
        string|int|float $actualCash,
        ?string $closingNotes = null,
    ): CashierShift {
        $user = $this->context->requireUser();
        $actualMinor = Money::toMinor($actualCash);

        if ($actualMinor < 0) {
            throw new BusinessRuleException('INVALID_ACTUAL_CASH', 'Actual cash count cannot be negative.', 422);
        }

        return DB::transaction(function () use ($shift, $user, $actualMinor, $closingNotes): CashierShift {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $lockedShift->isOpen()) {
                throw new BusinessRuleException('SHIFT_ALREADY_CLOSED', 'This shift is already closed.', 409);
            }

            $metrics = $this->calculateMetrics($lockedShift);
            $expectedMinor = $metrics['expected_cash_minor'];
            $differenceMinor = $actualMinor - $expectedMinor;

            $discrepancyStatus = match (true) {
                $differenceMinor === 0 => 'balanced',
                $differenceMinor > 0 => 'overage',
                default => 'shortage',
            };

            $closingSummary = [
                'discrepancy_status' => $discrepancyStatus,
                'breakdown_by_method' => $metrics['breakdown_by_method'],
                'formula' => 'opening_cash + cash_sales + paid_in - paid_out - cash_refunds',
            ];

            $oldSnapshot = [
                'status' => $lockedShift->status->value,
                'opening_cash' => $lockedShift->opening_cash,
            ];

            $lockedShift->update([
                'status' => ShiftStatus::Closed,
                'expected_cash' => Money::toDecimal($expectedMinor),
                'actual_cash' => Money::toDecimal($actualMinor),
                'cash_difference' => Money::toDecimal($differenceMinor),
                'total_sales' => Money::toDecimal($metrics['total_sales_minor']),
                'total_cash_sales' => Money::toDecimal($metrics['cash_sales_minor']),
                'total_non_cash_sales' => Money::toDecimal($metrics['non_cash_sales_minor']),
                'total_paid_in' => Money::toDecimal($metrics['paid_in_minor']),
                'total_paid_out' => Money::toDecimal($metrics['paid_out_minor']),
                'total_cash_refunds' => Money::toDecimal($metrics['cash_refunds_minor']),
                'transaction_count' => $metrics['transaction_count'],
                'closing_summary' => $closingSummary,
                'closing_notes' => $closingNotes,
                'closed_by' => $user->id,
                'closed_at' => CarbonImmutable::now(),
            ]);

            $this->context->setShift(null);

            $newSnapshot = [
                'status' => ShiftStatus::Closed->value,
                'expected_cash' => $lockedShift->expected_cash,
                'actual_cash' => $lockedShift->actual_cash,
                'cash_difference' => $lockedShift->cash_difference,
                'discrepancy_status' => $discrepancyStatus,
                'total_sales' => $lockedShift->total_sales,
                'total_cash_sales' => $lockedShift->total_cash_sales,
                'total_non_cash_sales' => $lockedShift->total_non_cash_sales,
                'total_paid_in' => $lockedShift->total_paid_in,
                'total_paid_out' => $lockedShift->total_paid_out,
                'total_cash_refunds' => $lockedShift->total_cash_refunds,
                'transaction_count' => $lockedShift->transaction_count,
                'closing_notes' => $lockedShift->closing_notes,
            ];

            $this->audit->record(
                AuditAction::SHIFT_CLOSED,
                $lockedShift,
                oldValues: $oldSnapshot,
                newValues: $newSnapshot,
            );

            $this->outbox->record('shift.closed', $lockedShift, $lockedShift->uuid, array_merge(
                ['shift_number' => $lockedShift->shift_number],
                $newSnapshot,
            ));

            return $lockedShift->fresh(['cashier', 'terminal', 'cashMovements.user']) ?? $lockedShift;
        });
    }

    /**
     * @return array{
     *     opening_cash_minor: int,
     *     cash_sales_minor: int,
     *     non_cash_sales_minor: int,
     *     total_sales_minor: int,
     *     paid_in_minor: int,
     *     paid_out_minor: int,
     *     cash_refunds_minor: int,
     *     expected_cash_minor: int,
     *     transaction_count: int,
     *     breakdown_by_method: array<string, string>
     * }
     */
    private function calculateMetrics(CashierShift $shift): array
    {
        $openingMinor = Money::toMinor($shift->opening_cash);

        $payments = $shift->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->get(['method', 'amount']);

        $breakdownMinor = [];
        foreach (PaymentMethod::cases() as $method) {
            $breakdownMinor[$method->value] = 0;
        }

        $cashSalesMinor = 0;
        $nonCashSalesMinor = 0;

        foreach ($payments as $payment) {
            $amt = Money::toMinor($payment->amount);
            $methodKey = $payment->method->value;
            $breakdownMinor[$methodKey] = ($breakdownMinor[$methodKey] ?? 0) + $amt;

            if ($payment->method === PaymentMethod::Cash) {
                $cashSalesMinor += $amt;
            } else {
                $nonCashSalesMinor += $amt;
            }
        }

        $movements = $shift->cashMovements()->get(['type', 'amount']);
        $paidInMinor = 0;
        $paidOutMinor = 0;

        foreach ($movements as $m) {
            $amt = Money::toMinor($m->amount);
            if ($m->type === CashMovementType::PaidIn) {
                $paidInMinor += $amt;
            } else {
                $paidOutMinor += $amt;
            }
        }

        $cashRefunds = $shift->refunds()
            ->where('method', PaymentMethod::Cash->value)
            ->get(['amount']);

        $cashRefundsMinor = 0;
        foreach ($cashRefunds as $refund) {
            $cashRefundsMinor += Money::toMinor($refund->amount);
        }

        $expectedCashMinor = $openingMinor + $cashSalesMinor + $paidInMinor - $paidOutMinor - $cashRefundsMinor;

        $txCount = $shift->orders()
            ->whereIn('status', [
                OrderStatus::Completed->value,
                OrderStatus::PartiallyRefunded->value,
                OrderStatus::Refunded->value,
            ])
            ->count();

        $breakdownDecimal = [];
        foreach ($breakdownMinor as $method => $minor) {
            $breakdownDecimal[$method] = Money::toDecimal($minor);
        }

        return [
            'opening_cash_minor' => $openingMinor,
            'cash_sales_minor' => $cashSalesMinor,
            'non_cash_sales_minor' => $nonCashSalesMinor,
            'total_sales_minor' => $cashSalesMinor + $nonCashSalesMinor,
            'paid_in_minor' => $paidInMinor,
            'paid_out_minor' => $paidOutMinor,
            'cash_refunds_minor' => $cashRefundsMinor,
            'expected_cash_minor' => $expectedCashMinor,
            'transaction_count' => $txCount,
            'breakdown_by_method' => $breakdownDecimal,
        ];
    }
}

