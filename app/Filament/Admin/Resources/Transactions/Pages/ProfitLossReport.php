<?php

namespace App\Filament\Admin\Resources\Transactions\Pages;

use App\Filament\Admin\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use App\Enums\TransactionType;
use App\Enums\TransactionModule;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class ProfitLossReport extends Page
{
    protected static string $resource = TransactionResource::class;

    protected static ?string $title = 'تقرير الأرباح والخسائر';

    protected string $view = 'filament.admin.resources.transactions.pages.profit-loss-report';

    public function getModuleStats(): array
    {
        $stats = [];
        $modules = TransactionModule::cases();

        foreach ($modules as $module) {
            $income = Transaction::where('module', $module->value)
                ->where('type', TransactionType::Income->value)
                ->sum('amount');

            $expense = Transaction::where('module', $module->value)
                ->where('type', TransactionType::Expense->value)
                ->sum('amount');

            $stats[] = [
                'label' => $module->label(),
                'income' => $income,
                'expense' => $expense,
                'profit' => $income - $expense,
            ];
        }

        return $stats;
    }

    public function getTotalStats(): array
    {
        $income = Transaction::where('type', TransactionType::Income->value)->sum('amount');
        $expense = Transaction::where('type', TransactionType::Expense->value)->sum('amount');

        return [
            'income' => $income,
            'expense' => $expense,
            'profit' => $income - $expense,
        ];
    }
}
