<?php

namespace App\Filament\Admin\Concerns;

trait HasSafarakFlightModulePageStyles
{
    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        $attrs = parent::getExtraBodyAttributes();
        $existing = isset($attrs['class']) ? (string) $attrs['class'] : '';
        $attrs['class'] = trim($existing.' safarak-flight-page safarak-flight-resource');

        return $attrs;
    }
}
