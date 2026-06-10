<?php

namespace App\Support\Finance;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * يجمع أرصدة نفس البنك/الخزنة/المحفظة عبر موديولات مختلفة.
 * مثال: بنك مصر (طيران + حج + تأشيرات) → رصيد إجمالي واحد مع تفصيل لكل موديول.
 */
final class UnifiedLiquidityGrouper
{
    /** @var array<int, string> */
    private const MODULE_SUFFIXES = [
        'طيران', 'حج', 'عمرة', 'تأشيرات', 'تأشيرة', 'باص', 'فوري', 'أونلاين', 'اونلاين',
        'مكتب', 'سياحة', 'عام', 'إداري', 'إدارة', 'تحويلات', 'محافظ',
        'flights', 'flight', 'hajj', 'umrah', 'umra', 'visa', 'visas', 'bus', 'fawry', 'online',
        'wallet', 'wallet_transfer', 'hajj_umra', 'tourism', 'office', 'general',
    ];

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<int, array<string, mixed>>
     */
    public static function group(Collection $accounts): array
    {
        $groups = [];

        foreach ($accounts as $account) {
            $key = self::resolveGroupKey($account);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'display_name' => self::resolveDisplayName($account),
                    'type' => self::typeValue($account),
                    'type_label' => self::typeLabel($account),
                    'currency' => strtoupper((string) ($account->currency ?: 'EGP')),
                    'total_balance' => 0.0,
                    'accounts_count' => 0,
                    'modules' => [],
                ];
            }

            $groups[$key]['total_balance'] += (float) $account->balance;
            $groups[$key]['accounts_count']++;

            $moduleKey = AccountModuleDivision::resolveModuleTypeKey($account->module_type, $account->module);

            if (! isset($groups[$key]['modules'][$moduleKey])) {
                $groups[$key]['modules'][$moduleKey] = [
                    'key' => $moduleKey,
                    'label' => AccountModuleDivision::moduleLabel($moduleKey),
                    'category' => AccountModuleDivision::divisionForModuleType($moduleKey),
                    'balance' => 0.0,
                    'accounts' => [],
                ];
            }

            $groups[$key]['modules'][$moduleKey]['balance'] += (float) $account->balance;
            $groups[$key]['modules'][$moduleKey]['accounts'][] = self::serializeAccount($account, $moduleKey);
        }

        $result = array_values($groups);

        foreach ($result as &$group) {
            $group['modules'] = array_values($group['modules']);
            usort($group['modules'], fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        }
        unset($group);

        usort($result, function (array $a, array $b): int {
            $typeOrder = array_flip(AccountModuleDivision::LIQUIDITY_TYPES);
            $ta = $typeOrder[$a['type']] ?? 99;
            $tb = $typeOrder[$b['type']] ?? 99;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }

            return strcmp($a['display_name'], $b['display_name']);
        });

        return $result;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<string, array{balance: float, count: int}>
     */
    public static function totalsByType(Collection $accounts): array
    {
        $totals = [];

        foreach (AccountModuleDivision::LIQUIDITY_TYPES as $type) {
            $totals[$type] = ['balance' => 0.0, 'count' => 0];
        }

        foreach ($accounts as $account) {
            $type = self::typeValue($account);
            if (! isset($totals[$type])) {
                $totals[$type] = ['balance' => 0.0, 'count' => 0];
            }
            $totals[$type]['balance'] += (float) $account->balance;
            $totals[$type]['count']++;
        }

        return $totals;
    }

    public static function resolveGroupKey(Account $account): string
    {
        $type = self::typeValue($account);
        $currency = strtoupper((string) ($account->currency ?: 'EGP'));

        if ($type === AccountType::Wallet->value && $account->wallet_provider) {
            $provider = $account->wallet_provider instanceof WalletProvider
                ? $account->wallet_provider->value
                : (string) $account->wallet_provider;

            return "{$type}|{$currency}|wallet:{$provider}";
        }

        $bankName = trim((string) ($account->getAttribute('bank_name') ?? ''));
        if ($type === AccountType::Bank->value && $bankName !== '') {
            return "{$type}|{$currency}|bank:".self::normalizeIdentity($bankName);
        }

        $treasuryType = trim((string) ($account->getAttribute('treasury_type') ?? ''));
        if ($treasuryType !== '') {
            return "{$type}|{$currency}|treasury_type:{$treasuryType}";
        }

        return "{$type}|{$currency}|name:".self::normalizeIdentity((string) $account->name);
    }

    public static function resolveDisplayName(Account $account): string
    {
        $bankName = trim((string) ($account->getAttribute('bank_name') ?? ''));
        if ($bankName !== '') {
            return $bankName;
        }

        if ($account->wallet_provider instanceof WalletProvider) {
            return $account->wallet_provider->label();
        }

        return self::prettifyName((string) $account->name);
    }

    public static function normalizeIdentity(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s*[-–—|\/]\s*(جنيه|دولار|ريال|دينار|يورو|درهم).*$/ui', '', $name) ?? $name;
        $name = preg_replace('/\s*[-–—|\/]\s*(EGP|USD|SAR|KWD|EUR|AED)\b.*$/ui', '', $name) ?? $name;

        foreach (self::MODULE_SUFFIXES as $suffix) {
            $pattern = '/\s*[-–—|\/]\s*'.preg_quote($suffix, '/').'\s*$/ui';
            $name = preg_replace($pattern, '', $name) ?? $name;
            $pattern = '/\s+'.preg_quote($suffix, '/').'\s*$/ui';
            $name = preg_replace($pattern, '', $name) ?? $name;
        }

        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower(trim($name));
    }

    private static function prettifyName(string $name): string
    {
        $normalized = self::normalizeIdentity($name);

        return $normalized !== '' ? mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8') : trim($name);
    }

    private static function typeValue(Account $account): string
    {
        $type = $account->type;

        return $type instanceof AccountType ? $type->value : (string) $type;
    }

    private static function typeLabel(Account $account): string
    {
        $type = $account->type;

        return $type instanceof AccountType ? $type->label() : (string) $type;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeAccount(Account $account, string $moduleKey): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => self::typeValue($account),
            'type_label' => self::typeLabel($account),
            'balance' => (float) $account->balance,
            'currency' => $account->currency,
            'module_type' => $moduleKey,
            'module_label' => AccountModuleDivision::moduleLabel($moduleKey),
            'is_vault' => (bool) $account->is_module_vault,
        ];
    }
}
