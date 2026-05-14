<?php

namespace App\Support\Finance;

/**
 * حامل السياق للطلب الحالي (HTTP / أمر / Job).
 */
final class PostingContextRegistry
{
    private ?PostingContext $current = null;

    public function set(?PostingContext $context): void
    {
        $this->current = $context;
    }

    public function peek(): ?PostingContext
    {
        return $this->current;
    }

    public function clear(): void
    {
        $this->current = null;
    }
}
