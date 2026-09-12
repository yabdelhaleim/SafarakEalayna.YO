<?php

namespace App\Exceptions;

use Exception;

/**
 * D5 FIX (2026-08-15): thrown when a caller attempts to recharge a
 * flight carrier whose `is_active` flag is false. Enforced at the
 * service layer so all callers (HTTP, CLI, internal services) are
 * protected — not just the controller-level validator.
 */
class InactiveFlightCarrierException extends Exception
{
    //
}
