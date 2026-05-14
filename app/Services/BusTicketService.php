<?php

namespace App\Services;

use App\Models\BusTicket;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BusTicketService
{
    public function create(array $data): BusTicket
    {
        return BusTicket::query()->create($data);
    }

    public function update(BusTicket $record, array $data): BusTicket
    {
        $record->update($data);

        return $record->fresh();
    }

    public function delete(BusTicket $record): bool
    {
        return (bool) $record->delete();
    }

    public function list(array $filters): LengthAwarePaginator
    {
        return BusTicket::query()
            ->with('employee')
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('departure_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('departure_date', '<=', $date))
            ->when($filters['payment_method'] ?? null, fn ($query, $method) => $query->where('payment_method', $method))
            ->when($filters['employee_id'] ?? null, fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('passenger_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('bus_name', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100));
    }

    public function getById(int $id): BusTicket
    {
        return BusTicket::query()->with('employee')->findOrFail($id);
    }

    public function getDailyReport(Carbon $date): array
    {
        $query = BusTicket::query()->whereDate('created_at', $date->toDateString());

        return $this->buildReport($query);
    }

    public function getMonthlyReport(int $year, int $month): array
    {
        $query = BusTicket::query()
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month);

        return $this->buildReport($query);
    }

    private function buildReport($query): array
    {
        $base = clone $query;
        $paymentBreakdown = (clone $query)
            ->selectRaw('payment_method, SUM(amount) as total_sales, SUM(profit) as total_profit, COUNT(*) as records_count')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'total_sales' => (string) $row->total_sales,
                'total_profit' => (string) $row->total_profit,
                'records_count' => (int) $row->records_count,
            ]);

        return [
            'total_sales' => (string) ($base->sum('amount') ?? '0'),
            'total_profit' => (string) ($base->sum('profit') ?? '0'),
            'total_records' => $base->count(),
            'payment_breakdown' => $paymentBreakdown,
        ];
    }
}
