<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Services\ActivityTrackingService;
use App\Services\Audit\AuditLogger;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AccountsReceivableController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
        private readonly ActivityTrackingService $activityTracker,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $activeOutlet = $this->context->outlet();
        $companyScope = $request->boolean('company_scope', false) && $user->hasPermission(Permission::CompanyDashboardView);
        $statusFilter = $request->string('status')->toString();

        $invoicesQuery = Invoice::query()->withoutGlobalScopes()
            ->with([
                'customer:id,code,name,phone,company_name,customer_group,credit_limit,outstanding_balance',
                'outlet:id,code,name,city',
                'salesOrder:id,so_number',
                'order:id,order_number,completed_at,payment_term,due_date',
                'allocations.customerPayment',
                'creator:id,name,username',
                'updater:id,name,username',
                'shift:id,name,code,start_time,end_time',
                'employeeShiftCheckin:id,work_date,checked_in_at,is_backdated,backdate_reason',
            ])
            ->where('organization_id', $user->organization_id);

        if (! $companyScope) {
            $outlet = $this->context->requireOutlet();
            $invoicesQuery->where('outlet_id', $outlet->id);
        }

        if ($statusFilter !== '') {
            $invoicesQuery->where('status', $statusFilter);
        }

        $invoices = $invoicesQuery->orderByDesc('issue_date')->orderByDesc('id')->get();

        // Calculate AR Aging buckets across open invoices in scope
        $today = Carbon::today();
        $aging = [
            'current_not_due' => 0.0,
            'days_1_30' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_over_90' => 0.0,
            'total_outstanding' => 0.0,
            'overdue_total' => 0.0,
            'overdue_count' => 0,
        ];

        foreach ($invoices as $inv) {
            $balance = (float) $inv->balance_due;
            if ($balance <= 0 || in_array($inv->status, ['paid', 'void'], true)) {
                continue;
            }

            $aging['total_outstanding'] += $balance;
            $dueDate = Carbon::parse($inv->due_date)->startOfDay();

            if ($dueDate->greaterThanOrEqualTo($today)) {
                $aging['current_not_due'] += $balance;
            } else {
                $daysOverdue = (int) $dueDate->diffInDays($today);
                $aging['overdue_total'] += $balance;
                $aging['overdue_count']++;

                if ($daysOverdue <= 30) {
                    $aging['days_1_30'] += $balance;
                } elseif ($daysOverdue <= 60) {
                    $aging['days_31_60'] += $balance;
                } elseif ($daysOverdue <= 90) {
                    $aging['days_61_90'] += $balance;
                } else {
                    $aging['days_over_90'] += $balance;
                }
            }
        }

        $paymentsQuery = CustomerPayment::query()->withoutGlobalScopes()
            ->with([
                'customer:id,code,name',
                'outlet:id,code,name',
                'allocations.invoice:id,invoice_number',
                'receiver:id,name,username',
                'shift:id,name,code',
            ])
            ->where('organization_id', $user->organization_id);

        if (! $companyScope && $activeOutlet !== null) {
            $paymentsQuery->where('outlet_id', $activeOutlet->id);
        }

        $payments = $paymentsQuery->orderByDesc('payment_date')->orderByDesc('id')->limit(40)->get();

        return response()->json([
            'scope' => $companyScope ? 'company' : 'outlet',
            'outlet_id' => $activeOutlet?->id,
            'aging_summary' => [
                'current_not_due' => number_format($aging['current_not_due'], 2, '.', ''),
                'days_1_30' => number_format($aging['days_1_30'], 2, '.', ''),
                'days_31_60' => number_format($aging['days_31_60'], 2, '.', ''),
                'days_61_90' => number_format($aging['days_61_90'], 2, '.', ''),
                'days_over_90' => number_format($aging['days_over_90'], 2, '.', ''),
                'total_outstanding' => number_format($aging['total_outstanding'], 2, '.', ''),
                'overdue_total' => number_format($aging['overdue_total'], 2, '.', ''),
                'overdue_count' => $aging['overdue_count'],
            ],
            'invoices' => $invoices,
            'payments' => $payments,
        ]);
    }

    /**
     * Records a Customer Payment and allocates it to an Invoice (supports Partial or Full settlement).
     * Guarantees:
     * - No duplicate payment when idempotency_key is provided
     * - Invoice cannot be paid beyond its remaining balance_due
     * - Customer outstanding_balance is atomically reduced
     */
    public function recordPayment(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'in:cash,bank_transfer,qris,giro'],
            'reference_number' => ['nullable', 'string', 'max:128'],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $idempotencyKey = (string) ($validated['idempotency_key'] ?? $request->header('X-Idempotency-Key', ''));

        if ($idempotencyKey !== '') {
            $existingPayment = CustomerPayment::query()->withoutGlobalScopes()
                ->with(['allocations.invoice'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPayment !== null) {
                return response()->json([
                    'payment' => $existingPayment,
                    'invoice' => $existingPayment->allocations->first()?->invoice,
                    'idempotent_replay' => true,
                ]);
            }
        }

        return DB::transaction(function () use ($user, $outlet, $validated, $idempotencyKey): JsonResponse {
            $invoice = Invoice::query()->withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)
                ->lockForUpdate()
                ->findOrFail((int) $validated['invoice_id']);

            if ((int) $invoice->outlet_id !== $outlet->id && ! $user->canAccessOutlet((int) $invoice->outlet_id)) {
                throw new BusinessRuleException('OUTLET_ACCESS_DENIED', 'You do not have access to this invoice\'s outlet.', 403);
            }

            $balanceDue = round((float) $invoice->balance_due, 2);
            $payAmount = round((float) $validated['amount'], 2);

            if ($balanceDue <= 0 || $invoice->status === 'paid') {
                throw new BusinessRuleException('INVOICE_ALREADY_PAID', 'Invoice ini sudah lunas sepenuhnya.', 422);
            }

            if ($payAmount > $balanceDue + 0.001) {
                throw new BusinessRuleException(
                    'INVOICE_OVERPAYMENT_NOT_ALLOWED',
                    sprintf(
                        'Nominal pembayaran (Rp %s) melebihi sisa tagihan invoice (Rp %s).',
                        number_format($payAmount, 0, ',', '.'),
                        number_format($balanceDue, 0, ',', '.'),
                    ),
                    422,
                );
            }

            $newPaidTotal = round((float) $invoice->paid_total + $payAmount, 2);
            $newBalanceDue = max(0.0, round((float) $invoice->grand_total - $newPaidTotal, 2));
            $newStatus = $newBalanceDue <= 0.001 ? 'paid' : 'partial';

            $paymentNumber = sprintf('PAY-%s-%s-%04d', $outlet->code, now()->format('Ymd'), random_int(1, 9999));

            $activeShiftCheckin = $this->context->employeeShiftCheckin();

            $payment = CustomerPayment::query()->withoutGlobalScopes()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $invoice->outlet_id,
                'customer_id' => $invoice->customer_id,
                'payment_number' => $paymentNumber,
                'method' => $validated['method'],
                'amount' => number_format($payAmount, 2, '.', ''),
                'allocated_amount' => number_format($payAmount, 2, '.', ''),
                'unallocated_amount' => '0.00',
                'reference_number' => $validated['reference_number'] ?? null,
                'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'received_by' => $user->id,
                'shift_id' => $activeShiftCheckin?->shift_id,
                'employee_shift_checkin_id' => $activeShiftCheckin?->id,
                'work_date' => $activeShiftCheckin?->work_date ?? now()->toDateString(),
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            ]);

            PaymentAllocation::query()->create([
                'customer_payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => number_format($payAmount, 2, '.', ''),
            ]);

            $invoice->update([
                'paid_total' => number_format($newPaidTotal, 2, '.', ''),
                'balance_due' => number_format($newBalanceDue, 2, '.', ''),
                'status' => $newStatus,
                'updated_by' => $user->id,
            ]);

            $customer = Customer::query()->withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($invoice->customer_id);

            $updatedCustomerBalance = max(0.0, round((float) $customer->outstanding_balance - $payAmount, 2));
            $customer->update([
                'outstanding_balance' => number_format($updatedCustomerBalance, 2, '.', ''),
            ]);

            $this->activityTracker->recordActivity(
                user: $user,
                outlet: $outlet,
                moduleName: 'receivables',
                actionType: 'pay',
                entityType: 'CustomerPayment',
                entityId: $payment->id,
                description: sprintf(
                    'Penerimaan pembayaran %s sebesar Rp %s untuk invoice %s (%s). Sisa tagihan Rp %s.',
                    $paymentNumber,
                    number_format($payAmount, 0, ',', '.'),
                    $invoice->invoice_number,
                    $newStatus === 'paid' ? 'LUNAS' : 'SEBAGIAN',
                    number_format($newBalanceDue, 0, ',', '.'),
                ),
                oldValues: ['balance_due' => $balanceDue, 'status' => $invoice->status],
                newValues: ['balance_due' => $newBalanceDue, 'status' => $newStatus, 'payment_number' => $paymentNumber],
                shiftCheckin: $activeShiftCheckin,
            );

            $this->audit->record(
                AuditAction::RECEIVABLE_PAYMENT_RECORDED,
                $payment,
                oldValues: [
                    'invoice_number' => $invoice->invoice_number,
                    'previous_balance_due' => number_format($balanceDue, 2, '.', ''),
                ],
                newValues: [
                    'payment_number' => $payment->payment_number,
                    'invoice_number' => $invoice->invoice_number,
                    'amount_paid' => number_format($payAmount, 2, '.', ''),
                    'new_balance_due' => number_format($newBalanceDue, 2, '.', ''),
                    'invoice_status' => $newStatus,
                ],
                actor: $user,
                overrides: ['outlet_id' => $invoice->outlet_id],
            );

            return response()->json([
                'payment' => $payment->load('allocations.invoice'),
                'invoice' => $invoice->fresh(['customer', 'outlet', 'allocations.customerPayment']),
                'customer' => $customer->fresh(),
            ], 201);
        });
    }
}

