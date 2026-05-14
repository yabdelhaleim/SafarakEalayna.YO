<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Pages;

use App\Enums\WalletTransactionType;
use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\Wallet\WalletTransaction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('عملية جديدة')
                ->modal(false),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('الكل')
                ->badge((string) WalletTransaction::query()->count()),

            'send' => Tab::make(WalletTransactionType::Send->label())
                ->badge((string) WalletTransaction::query()->where('type', WalletTransactionType::Send->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', WalletTransactionType::Send->value)),

            'receive' => Tab::make(WalletTransactionType::Receive->label())
                ->badge((string) WalletTransaction::query()->where('type', WalletTransactionType::Receive->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', WalletTransactionType::Receive->value)),
        ];
    }
}
