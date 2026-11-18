<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case EWallet = 'e_wallet';
    case BankTransfer = 'bank_transfer';

    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    /** Non-cash methods must carry a reference (approval code / RRN) for reconciliation. */
    public function requiresReference(): bool
    {
        return in_array($this, [self::DebitCard, self::CreditCard, self::BankTransfer], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Qris => 'QRIS',
            self::DebitCard => 'Debit Card',
            self::CreditCard => 'Credit Card',
            self::EWallet => 'E-Wallet',
            self::BankTransfer => 'Bank Transfer',
        };
    }
}
