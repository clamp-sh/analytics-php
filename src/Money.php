<?php

declare(strict_types=1);

namespace Clamp\Analytics;

/**
 * A typed monetary value attached to any event property.
 *
 * Send multiple per event when a purchase has subtotal, tax, shipping, etc.
 *
 *     Analytics::track('purchase', [
 *         'plan' => 'pro',
 *         'total' => new Money(29.00, 'USD'),
 *         'tax' => new Money(4.35, 'USD'),
 *     ]);
 */
final class Money
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {
    }

    /**
     * @return array{amount: float, currency: string}
     */
    public function toWire(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
