<?php

declare(strict_types=1);

namespace Clamp\Analytics;

/**
 * Raised when the ingestion API returns a non-2xx response.
 */
final class ClampHttpException extends ClampException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
        parent::__construct("clamp_analytics: {$statusCode} {$body}");
    }
}
