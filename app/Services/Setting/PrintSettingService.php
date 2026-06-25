<?php

namespace App\Services\Setting;

use App\Models\Setting\PrintSetting;
use Illuminate\Support\Facades\DB;

class PrintSettingService
{
    /** @var array<string, string> */
    public const MODULE_LABELS = [
        'flight' => 'طيران',
        'bus' => 'باصات',
        'hajj_umra' => 'حج وعمرة',
        'visa' => 'تأشيرات',
        'online' => 'أونلاين',
        'fawry' => 'فوري',
        'wallet' => 'محافظ',
        'service' => 'خدمات',
        'general' => 'مالية عامة',
    ];

    /** @var array<string, string> */
    public const DOCUMENT_LABELS = [
        'ticket' => 'تذكرة',
        'invoice' => 'فاتورة / سند / كشف',
    ];

    public function defaultModules(): array
    {
        $defaults = [];
        foreach (array_keys(self::MODULE_LABELS) as $module) {
            $defaults[$module] = [
                'ticket' => true,
                'invoice' => true,
            ];
        }

        return $defaults;
    }

    public function get(): PrintSetting
    {
        $setting = PrintSetting::query()->first();
        if ($setting) {
            return $setting;
        }

        return PrintSetting::query()->create([
            'company_name_ar' => 'سفرك علينا',
            'company_name_en' => 'Safarak Ealayna',
            'address' => null,
            'phones' => null,
            'finance_label' => 'المالية والمحاسب',
            'show_amount_due' => true,
            'modules' => $this->defaultModules(),
            'base_capital' => 1000000.00,
            'office_base_capital' => 0.00,
        ]);
    }

    public function toArray(?PrintSetting $setting = null): array
    {
        $setting ??= $this->get();

        return [
            'company_name_ar' => $setting->company_name_ar,
            'company_name_en' => $setting->company_name_en,
            'address' => $setting->address,
            'phones' => $setting->phones,
            'finance_label' => $setting->finance_label ?: 'المالية والمحاسب',
            'show_amount_due' => (bool) $setting->show_amount_due,
            'base_capital' => (float) ($setting->base_capital ?? 1000000.00),
            'office_base_capital' => (float) ($setting->office_base_capital ?? 0.00),
            'modules' => $this->normalizeModules($setting->modules),
            'module_options' => collect(self::MODULE_LABELS)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])->values()->all(),
            'document_options' => collect(self::DOCUMENT_LABELS)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])->values()->all(),
        ];
    }

    public function update(array $data): PrintSetting
    {
        return DB::transaction(function () use ($data) {
            $setting = $this->get();

            $setting->update([
                'company_name_ar' => $data['company_name_ar'] ?? $setting->company_name_ar,
                'company_name_en' => $data['company_name_en'] ?? $setting->company_name_en,
                'address' => $data['address'] ?? null,
                'phones' => $data['phones'] ?? null,
                'finance_label' => $data['finance_label'] ?? $setting->finance_label,
                'show_amount_due' => array_key_exists('show_amount_due', $data)
                    ? (bool) $data['show_amount_due']
                    : $setting->show_amount_due,
                'base_capital' => isset($data['base_capital']) ? (float) $data['base_capital'] : $setting->base_capital,
                'office_base_capital' => isset($data['office_base_capital']) ? (float) $data['office_base_capital'] : $setting->office_base_capital,
                'modules' => $this->normalizeModules($data['modules'] ?? $setting->modules),
            ]);

            return $setting->fresh();
        });
    }

    public function isEnabled(string $module, string $documentType): bool
    {
        $modules = $this->normalizeModules($this->get()->modules);

        return (bool) ($modules[$module][$documentType] ?? false);
    }

    public function normalizeModules(?array $modules): array
    {
        $normalized = $this->defaultModules();

        if (! is_array($modules)) {
            return $normalized;
        }

        foreach ($normalized as $module => $docs) {
            if (! isset($modules[$module]) || ! is_array($modules[$module])) {
                continue;
            }
            foreach (['ticket', 'invoice'] as $doc) {
                if (array_key_exists($doc, $modules[$module])) {
                    $normalized[$module][$doc] = (bool) $modules[$module][$doc];
                }
            }
        }

        return $normalized;
    }
}
