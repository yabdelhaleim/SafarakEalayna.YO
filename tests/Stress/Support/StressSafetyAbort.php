<?php

declare(strict_types=1);

namespace Tests\Stress\Support;

/**
 * Thrown by StressSafetyGuard when the harness is asked to touch a
 * forbidden database or environment. The message is intentionally
 * loud so it surfaces in any CI/log aggregator.
 */
final class StressSafetyAbort extends \RuntimeException
{
}
