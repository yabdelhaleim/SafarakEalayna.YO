{{--
    AccountResource category tabs (Phase 4 — Account Unification).

    Renders a horizontal tab strip above the Filament table. Each tab links
    to the same page with a `?category=` query parameter. The active tab is
    derived from the current request's `category` value (default = all).

    Categories map to AccountModuleContract::LIQUIDITY_TYPES / SUBJECT_TYPES
    / INTERNAL_TYPES — defined as constants in the contract, never hardcoded
    here, so the contract remains the single source of truth.
--}}
@php
    use Illuminate\Support\Facades\Request;

    $current = Request::query('category');

    // ['' => 'All', 'liquidity' => 'Liquidity (Operating)', ...]
    $tabs = [
        ''          => 'الكل — All',
        'liquidity' => 'تشغيلي — Liquidity (cashbox / wallet / bank)',
        'subject'   => 'موضوعي — Subject (customer / supplier)',
        'internal'  => 'إقفال — Internal GL (expense / revenue / liability / owner)',
    ];
@endphp

<nav
    class="fi-ta-tabs flex flex-wrap items-center gap-1 mb-4 border-b border-gray-200 dark:border-white/10"
    aria-label="Account category"
>
    @foreach ($tabs as $key => $label)
        @php
            $isActive = ($current ?? '') === $key;
            $href = $key === '' ? Request::url() : Request::url() . '?category=' . $key;
        @endphp

        <a
            href="{{ $href }}"
            @class([
                'fi-ta-tab px-4 py-2 text-sm font-medium border-b-2 -mb-px transition',
                'border-primary-500 text-primary-600 dark:text-primary-400' => $isActive,
                'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! $isActive,
            ])
            @if ($isActive) aria-current="page" @endif
        >
            {{ $label }}
        </a>
    @endforeach
</nav>