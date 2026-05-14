<?php

namespace App\Filament\Admin\Concerns;

trait HasSafarakWalletModulePageStyles
{
    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        $attrs = parent::getExtraBodyAttributes();
        $existing = isset($attrs['class']) ? (string) $attrs['class'] : '';
        $attrs['class'] = trim($existing.' safarak-wallet-page safarak-wallet-resource');

        return $attrs;
    }
}
