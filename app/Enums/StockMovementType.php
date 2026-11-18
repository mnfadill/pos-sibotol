<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementType: string
{
    case Sale = 'sale';
    case Void = 'void';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case Opname = 'opname';
    case Receive = 'receive';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case SalesOrder = 'sales_order';

    // Enhanced Enterprise Ledger Movement Types
    case PurchaseReceipt = 'purchase_receipt';
    case SalesOut = 'sales_out';
    case SalesReturn = 'sales_return';
    case PurchaseReturn = 'purchase_return';
    case DamagedStock = 'damaged_stock';
    case ExpiredStock = 'expired_stock';
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return match ($this) {
            self::Sale, self::SalesOut => 'Sales Out (POS)',
            self::SalesOrder => 'B2B Sales Delivery',
            self::Void => 'Transaction Void Restock',
            self::Refund, self::SalesReturn => 'Sales Return / Refund',
            self::Receive, self::PurchaseReceipt => 'Purchase Receipt (GR)',
            self::PurchaseReturn => 'Purchase Return to Supplier',
            self::TransferOut => 'Stock Transfer Out',
            self::TransferIn => 'Stock Transfer In',
            self::Adjustment => 'Manual Stock Adjustment',
            self::Opname => 'Stock Opname Reconciliation',
            self::DamagedStock => 'Damaged Stock Quarantine',
            self::ExpiredStock => 'Expired Stock Write-Off',
            self::OpeningBalance => 'Opening Stock Balance',
        };
    }
}
