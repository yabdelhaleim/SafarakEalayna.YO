<?php

namespace App\Exceptions;

use Exception;

/**
 * Phase 11.1 B-7 DEFECT FIX (2026-08-20): thrown when a caller attempts to
 * debit a flight system whose `is_active` flag is false. Enforced at the
 * model layer (FlightSystem::debit) so all callers — HTTP, CLI, internal
 * services — are protected, not just the controller-level validator.
 *
 * Mirrors InactiveFlightCarrierException for symmetry.
 */
class InactiveFlightSystemException extends Exception
{
    //
}