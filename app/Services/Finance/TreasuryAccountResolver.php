<?php

namespace App\Services\Finance;

use App\Enums\TreasuryAccount;
use App\Models\Account;

/**
 * Resolves TreasuryAccount API codes / explicit IDs to ledger {@see Account} records.
 *
 * Lookup order:
 * 1) Explicit account_id (when valid & active)
 * 2) Env map accounting.treasury_route.by_code[ENUM_VALUE]
 * 3) label_aliases keyed by ENUM_VALUE (e.g. TREASURY_CASH_EGP => id or account name)
 * 4) Active account matching enum label() (Arabic UI label = accounts.name)
 * 5) label_aliases keyed by label() — value may be numeric account id or target accounts.name string
 */
final class TreasuryAccountResolver
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function resolve(?string $treasuryEnumValue, ?int $explicitAccountId): int
    {
        if ($explicitAccountId !== null && $explicitAccountId > 0) {
            if (! Account::query()->whereKey($explicitAccountId)->where('is_active', true)->exists()) {
                throw new \InvalidArgumentException('معرّف الحساب غير صالح أو الحساب غير نشط.');
            }

            return $explicitAccountId;
        }

        if ($treasuryEnumValue === null || $treasuryEnumValue === '') {
            throw new \InvalidArgumentException('يجب تمرير رمز الخزينة (TreasuryAccount) أو account_id.');
        }

        $enum = TreasuryAccount::tryFrom($treasuryEnumValue);
        if (! $enum instanceof TreasuryAccount) {
            throw new \InvalidArgumentException(
                'قيمة treasury غير معروفة. استخدم رمزًا مثل TREASURY_CASH_EGP أو ضع account_id.'
            );
        }

        $byCode = config('accounting.treasury_route.by_code', []);
        if (is_array($byCode) && isset($byCode[$enum->value])) {
            $mapped = (int) $byCode[$enum->value];
            if ($mapped > 0 && Account::query()->whereKey($mapped)->where('is_active', true)->exists()) {
                return $mapped;
            }
        }

        $aliases = config('accounting.treasury_route.label_aliases', []);
        if (is_array($aliases) && array_key_exists($enum->value, $aliases)) {
            $resolved = self::resolveAliasValue($aliases[$enum->value]);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $label = $enum->label();
        $idsByLabel = Account::query()
            ->where('name', $label)
            ->where('is_active', true)
            ->pluck('id');

        if ($idsByLabel->count() > 1) {
            throw new \RuntimeException(
                'Treasury resolver: أكثر من حساب نشط يحمل الاسم «'.$label.'». عيّن accounting.treasury_route.by_code أو label_aliases دون التباس.'
            );
        }

        if ($idsByLabel->count() === 1) {
            return (int) $idsByLabel->first();
        }

        if (is_array($aliases) && array_key_exists($label, $aliases)) {
            $resolved = self::resolveAliasValue($aliases[$label]);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new \InvalidArgumentException(
            'لم يُعثر على حساب محاسبي لـ «'.$enum->value.'». ضع ACCOUNTING_TAS_*_ID في .env أو عيّن treasury_route.by_code أو label_aliases (رمز أو تسمية عربية ← id أو اسم الحساب).'
        );
    }

    /**
     * Alias value: positive int / numeric string ⇒ accounts.id؛ وإلا سلسلة نصية ⇒ البحث بـ accounts.name النشطة.
     */
    private static function resolveAliasValue(mixed $raw): ?int
    {
        if (is_int($raw) || is_float($raw)) {
            $id = (int) $raw;

            return $id > 0 && Account::query()->whereKey($id)->where('is_active', true)->exists()
                ? $id : null;
        }

        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                return null;
            }

            if (ctype_digit($trim)) {
                $id = (int) $trim;

                return $id > 0 && Account::query()->whereKey($id)->where('is_active', true)->exists()
                    ? $id : null;
            }

            $count = Account::query()
                ->where('name', $trim)
                ->where('is_active', true)
                ->count();

            if ($count > 1) {
                throw new \RuntimeException(
                    'Treasury resolver: أكثر من حساب نشط يطابق الاسم «'.$trim.'». عيّن معرفًا أو alias فريدًا.'
                );
            }

            $id = Account::query()
                ->where('name', $trim)
                ->where('is_active', true)
                ->value('id');

            return $id ? (int) $id : null;
        }

        return null;
    }
}
