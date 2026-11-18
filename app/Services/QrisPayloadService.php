<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Outlet;
use App\Support\Money;

/**
 * Generates Indonesian Standard QRIS (EMVCo Merchant-Presented Mode) dynamic payloads
 * with CRC-16/CCITT-FALSE checksum (Tag 63).
 */
final class QrisPayloadService
{
    public function generate(Outlet $outlet, int $amountMinor, string $referenceNumber): string
    {
        $amountDecimal = (string) intdiv($amountMinor, 100);
        $merchantName = strtoupper(substr($outlet->qris_merchant_name ?: $outlet->name, 0, 25));
        $merchantCity = 'INDONESIA';

        $tlv = $this->tag('00', '01') // Payload Format Indicator
            .$this->tag('01', '12') // Point of Initiation Method: 12 = Dynamic QR
            .$this->tag('26',
                $this->tag('00', 'ID.CO.QRIS.WWW')
                .$this->tag('01', '936009143'.str_pad((string) $outlet->id, 6, '0', STR_PAD_LEFT))
                .$this->tag('02', strtoupper($outlet->code))
                .$this->tag('03', 'UMI')
            )
            .$this->tag('52', '5812') // MCC: Restaurants / Retail
            .$this->tag('53', '360')  // Transaction Currency: 360 = IDR
            .$this->tag('54', $amountDecimal) // Transaction Amount
            .$this->tag('58', 'ID')   // Country Code
            .$this->tag('59', $merchantName)
            .$this->tag('60', $merchantCity)
            .$this->tag('62', $this->tag('01', substr($referenceNumber, 0, 25)))
            .'6304'; // CRC Tag + Length

        return $tlv.$this->crc16CcittFalse($tlv);
    }

    /**
     * Format decimal string amount into dynamic QRIS payload.
     */
    public function generateForDecimalAmount(Outlet $outlet, string $amount, string $referenceNumber): string
    {
        return $this->generate($outlet, Money::toMinor($amount), $referenceNumber);
    }

    private function tag(string $id, string $value): string
    {
        $len = str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT);

        return $id.$len.$value;
    }

    /**
     * Standard EMVCo CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF).
     */
    public function crc16CcittFalse(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}

