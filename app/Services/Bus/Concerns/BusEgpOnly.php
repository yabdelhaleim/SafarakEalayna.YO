<?php

namespace App\Services\Bus\Concerns;

/**
 * BusEgpOnly — centralized EGP-only enforcement helpers for the Bus module.
 *
 * Product requirement: BUS = EGP ONLY.
 *
 * The Bus module does not support multi-currency booking, payment, refund,
 * inventory, FX conversion, or cross-currency movement. Legacy currency
 * columns are retained for backward compatibility, but every writer must
 * force them to EGP/1.0 and every reader must treat them as authoritative.
 *
 * If a caller (API, Filament, Vue, or direct service invocation) attempts
 * to introduce a non-EGP value, the corresponding guard throws.
 *
 * @see .zcode/plans/BUS_EGP_ONLY_AUDIT_REPORT_20260826.md
 */
trait BusEgpOnly
{
    /**
     * Canonical currency for the Bus module.
     */
    public const BUS_CURRENCY = 'EGP';

    /**
     * Canonical FX rate for the Bus module (always 1.0 — no FX).
     */
    public const BUS_EXCHANGE_RATE_TO_EGP = 1.0;

    /**
     * Assert that a value is the canonical Bus currency (EGP).
     * Accepts null/'EGP'/'egp' as valid; rejects everything else.
     *
     * @throws \InvalidArgumentException
     */
    protected function assertBusCurrency(?string $currency, string $context = 'value'): void
    {
        $normalized = $currency === null ? self::BUS_CURRENCY : strtoupper(trim($currency));
        if ($normalized !== self::BUS_CURRENCY) {
            throw new \InvalidArgumentException(
                "Bus module is EGP-only (EGP). Rejected non-EGP currency ({$context}={$currency}). ".
                'وحدة الباص تعمل بالجنيه المصري فقط. القيمة المرفوضة: '.$currency
            );
        }
    }

    /**
     * Assert that a numeric FX rate is the canonical 1.0 (no FX).
     *
     * @throws \InvalidArgumentException
     */
    protected function assertBusExchangeRate(float $rate, string $context = 'rate'): void
    {
        if (abs($rate - self::BUS_EXCHANGE_RATE_TO_EGP) > 0.0000001) {
            throw new \InvalidArgumentException(
                "Bus module is EGP-only (FX rate must be 1.0). Rejected rate ({$context}={$rate}). ".
                'وحدة الباص لا تدعم سعر صرف مخصص. القيمة المرفوضة: '.$rate
            );
        }
    }

    /**
     * Coerce any caller-supplied currency value to EGP. Used at writer
     * boundaries where we want to silently normalize instead of throwing —
     * for example, when a test fixture or seed script passes a non-EGP
     * value that the writer does not actually use.
     */
    protected function coerceToEgp(mixed $currency): string
    {
        if ($currency === null || $currency === '' || $currency === []) {
            return self::BUS_CURRENCY;
        }
        if (is_string($currency)) {
            $normalized = strtoupper(trim($currency));
            if ($normalized !== self::BUS_CURRENCY) {
                throw new \InvalidArgumentException(
                    'Bus module is EGP-only (EGP). Rejected non-EGP currency: '.$currency
                );
            }

            return self::BUS_CURRENCY;
        }

        throw new \InvalidArgumentException(
            'Bus module is EGP-only (EGP). Invalid currency type.'
        );
    }

    /**
     * Coerce any caller-supplied FX rate to 1.0.
     */
    protected function coerceToOne(mixed $rate): float
    {
        if ($rate === null || $rate === '' || $rate === []) {
            return self::BUS_EXCHANGE_RATE_TO_EGP;
        }
        $value = (float) $rate;
        if (abs($value - self::BUS_EXCHANGE_RATE_TO_EGP) > 0.0000001) {
            throw new \InvalidArgumentException(
                'Bus module is EGP-only (FX rate must be 1.0). Rejected rate: '.$value
            );
        }

        return self::BUS_EXCHANGE_RATE_TO_EGP;
    }

    /**
     * Build the standard EGP snapshot array for any Bus entity that
     * historically carried a multi-currency payload.
     *
     * @return array{currency: string, exchange_rate_to_egp: float}
     */
    protected function egpSnapshot(): array
    {
        return [
            'currency' => self::BUS_CURRENCY,
            'exchange_rate_to_egp' => self::BUS_EXCHANGE_RATE_TO_EGP,
        ];
    }

    /**
     * Build the standard EGP refund snapshot array for BusRefundRequest.
     *
     * @return array{original_currency: string, refund_currency: string, refund_exchange_rate: float, base_currency_refund: float}
     */
    protected function egpRefundSnapshot(float $refundAmount): array
    {
        return [
            'original_currency' => self::BUS_CURRENCY,
            'refund_currency' => self::BUS_CURRENCY,
            'refund_exchange_rate' => self::BUS_EXCHANGE_RATE_TO_EGP,
            'base_currency_refund' => round($refundAmount, 2),
        ];
    }
}
