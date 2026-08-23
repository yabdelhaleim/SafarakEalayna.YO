<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * FINDING UX-1 (MED) REMEDIATION (2026-08-21):
 *
 * Thrown when a request is well-formed and authorized but violates a
 * business-rule invariant (e.g. insufficient balance, currency mismatch
 * that snuck past validation, account inactive at commit time). HTTP 409
 * Conflict is the correct response because the request itself is valid —
 * the server's STATE conflicts with it. 422 (ValidationException) is
 * reserved for input-shape errors.
 *
 * Mapped to 409 in `bootstrap/app.php` (withExceptions closure) so the
 * `ApiResponse::error()` shape is preserved on the wire.
 *
 * Usage:
 *     throw new BusinessLogicException('رصيد الحساب غير كافٍ: '.$name, [
 *         'account_id' => $account->id,
 *         'required'   => $amount,
 *         'available'  => $account->balance,
 *     ]);
 */
class BusinessLogicException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}