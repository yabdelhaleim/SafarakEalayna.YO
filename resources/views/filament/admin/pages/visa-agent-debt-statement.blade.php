<x-filament-panels::page>
    <div class="space-y-6">
        @if($this->agentId)
            @php
                $agent = \App\Models\HajjUmra\VisaAgent::find($this->agentId);
            @endphp

            @if($agent)
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="text-sm text-gray-500 mb-1">اسم الوكيل</div>
                        <div class="text-lg font-semibold">{{ $agent->company_name }}</div>
                    </div>

                    @if($agent->account)
                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="text-sm text-gray-500 mb-1">الرصيد المالي</div>
                            <div class="text-lg font-semibold {{ $agent->account->balance >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ number_format($agent->account->balance, 2) }} ج.م
                            </div>
                        </div>

                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="text-sm text-gray-500 mb-1">رقم الحساب</div>
                            <div class="text-lg font-semibold">#{{ $agent->account->id }}</div>
                        </div>
                    @endif
                </div>
            @endif
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook('visa-agent-debt-statement.before-table') }}

        {{ $this->table }}
    </div>
</x-filament-panels::page>
