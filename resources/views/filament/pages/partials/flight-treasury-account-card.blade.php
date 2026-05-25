@props([
    /** @var \App\Models\Account $account */
    'account',
])

@php
    use App\Enums\AccountType;

    $editUrl = match ($account->type) {
        AccountType::Bank => \App\Filament\Admin\Resources\BankAccounts\BankAccountResource::getUrl(
            'edit',
            ['record' => $account],
        ),
        AccountType::Wallet => \App\Filament\Admin\Resources\FlightWallets\FlightWalletResource::getUrl(
            'edit',
            ['record' => $account],
        ),
        default => \App\Filament\Admin\Resources\Accounts\AccountResource::getUrl('edit', [
            'record' => $account,
        ]),
    };

    $typeLabel = $account->type instanceof AccountType
        ? $account->type->label()
        : AccountType::tryFrom((string) $account->type)?->label() ?? (string) $account->type;
@endphp

<div class="safarak-flight-card group relative flex flex-col gap-3 overflow-hidden p-5 transition hover:border-primary-500/30">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
            <h4 class="text-base font-semibold leading-snug text-white">
                {{ $account->name }}
            </h4>
            <p class="mt-1 text-xs text-slate-400">{{ $typeLabel }}</p>
            @if ($account->type === AccountType::Wallet && (filled($account->wallet_provider) || filled($account->wallet_number)))
                <div class="mt-2 space-y-1 text-xs leading-relaxed text-slate-300">
                    @if (filled($account->wallet_provider))
                        <p>
                            <span class="text-slate-500">نوع المحفظة:</span>
                            {{ $account->walletProviderLabel() }}
                        </p>
                    @endif
                    @if (filled($account->wallet_number))
                        <p class="font-mono">
                            <span class="text-slate-500">رقم المحفظة:</span>
                            {{ $account->wallet_number }}
                        </p>
                    @endif
                </div>
            @endif
        </div>
        <x-filament::badge color="gray" size="sm">
            {{ strtoupper($account->currency ?? 'EGP') }}
        </x-filament::badge>
    </div>

    @if (filled($account->notes))
        <p class="line-clamp-2 text-xs leading-relaxed text-slate-400">
            {{ $account->notes }}
        </p>
    @endif

    <div class="flex flex-wrap gap-2">
        <x-filament::button
            color="gray"
            size="xs"
            outlined
            tag="a"
            :href="$editUrl"
        >
            تعديل
        </x-filament::button>

        <x-filament::button
            color="danger"
            size="xs"
            outlined
            wire:click="deleteAccount({{ $account->id }})"
            wire:confirm="هل أنت متأكد أنك تريد حذف هذا الحساب؟"
        >
            حذف
        </x-filament::button>
    </div>

    <div class="mt-auto border-t border-gray-100 pt-3 dark:border-white/10">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            الرصيد الحالي
        </p>
        <p
            class="mt-0.5 text-2xl font-bold tabular-nums tracking-tight text-primary-600 dark:text-primary-400"
        >
            {{ number_format((float) $account->balance, 2) }}
        </p>
    </div>
</div>
