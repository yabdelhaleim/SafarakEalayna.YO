<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->migrateHajjUmrah();
            $this->migrateVisas();
            $this->assertCounts();

            if (Schema::hasTable('hajj_umrah')) {
                Schema::drop('hajj_umrah');
            }
            if (Schema::hasTable('visas')) {
                Schema::drop('visas');
            }
        });
    }

    public function down(): void
    {
        // Backward rollback: restore duplicated tables only.
        if (!Schema::hasTable('hajj_umrah')) {
            Schema::create('hajj_umrah', function ($table): void {
                $table->id();
                $table->string('client_name');
                $table->string('phone', 20);
                $table->date('birth_date');
                $table->string('passport_number', 50)->unique();
                $table->string('country');
                $table->string('nationality');
                $table->text('client_notes')->nullable();
                $table->json('companions')->nullable();
                $table->string('program_name');
                $table->unsignedTinyInteger('duration_nights');
                $table->string('mecca_hotel');
                $table->unsignedTinyInteger('mecca_nights');
                $table->string('madina_hotel')->nullable();
                $table->unsignedTinyInteger('madina_nights')->nullable();
                $table->enum('accommodation_type', ['single', 'double', 'triple', 'quad'])->default('quad');
                $table->enum('booking_status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending');
                $table->date('departure_date');
                $table->date('return_date');
                $table->string('airline')->nullable();
                $table->string('trip_supervisor')->nullable();
                $table->string('executing_company')->nullable();
                $table->string('executor')->nullable();
                $table->decimal('purchase_price', 12, 2);
                $table->decimal('selling_price', 12, 2);
                $table->decimal('profit', 12, 2)->default(0);
                $table->enum('payment_method', ['cash', 'bank_transfer', 'cash_wallet', 'office_safe', 'office_drawer']);
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->decimal('remaining_amount', 12, 2)->default(0);
                $table->string('payment_reference')->nullable();
                $table->text('payment_notes')->nullable();
                $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
                $table->text('employee_notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('visas')) {
            Schema::create('visas', function ($table): void {
                $table->id();
                $table->string('client_name');
                $table->string('phone', 20);
                $table->date('birth_date');
                $table->string('passport_number', 50);
                $table->string('country');
                $table->string('nationality');
                $table->text('client_notes')->nullable();
                $table->enum('visa_type', ['tourist', 'work', 'student', 'residence', 'transit', 'hajj', 'umrah', 'business', 'other']);
                $table->enum('visa_duration', ['one_month', 'three_months', 'six_months', 'one_year', 'two_years', 'five_years', 'ten_years', 'other']);
                $table->enum('entry_type', ['single', 'double', 'multiple'])->default('single');
                $table->string('destination_country');
                $table->string('agent_company');
                $table->string('agent_name');
                $table->enum('visa_status', ['pending', 'submitted', 'approved', 'rejected', 'cancelled'])->default('pending');
                $table->text('visa_notes')->nullable();
                $table->decimal('purchase_price', 12, 2);
                $table->decimal('selling_price', 12, 2);
                $table->decimal('profit', 12, 2)->default(0);
                $table->enum('payment_method', ['cash', 'bank_transfer', 'cash_wallet', 'office_safe', 'office_drawer']);
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->decimal('remaining_amount', 12, 2)->default(0);
                $table->string('payment_reference')->nullable();
                $table->text('payment_notes')->nullable();
                $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
                $table->text('employee_notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    private function migrateHajjUmrah(): void
    {
        if (!Schema::hasTable('hajj_umrah') || !Schema::hasTable('hajj_umra_bookings')) {
            return;
        }

        $rows = DB::table('hajj_umrah')->orderBy('id')->get();
        foreach ($rows as $row) {
            $customerId = DB::table('customers')->updateOrInsert(
                ['phone' => $row->phone],
                [
                    'full_name' => $row->client_name,
                    'passport_number' => $row->passport_number,
                    'date_of_birth' => $row->birth_date,
                    'city' => $row->country,
                    'affiliation' => $row->nationality,
                    'notes' => $row->client_notes,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $customer = DB::table('customers')->where('phone', $row->phone)->first();

            $programData = [
                'program_name' => $row->program_name,
                'program_type' => str_contains(strtolower($row->program_name), 'hajj') ? 'HAJJ' : 'UMRA',
                'total_nights' => (int) $row->duration_nights,
                'accommodation_type' => strtoupper((string) $row->accommodation_type),
                'mecca_hotel_name' => $row->mecca_hotel,
                'mecca_nights' => (int) $row->mecca_nights,
                'medina_hotel_name' => $row->madina_hotel,
                'medina_nights' => $row->madina_nights,
                'departure_date' => $row->departure_date,
                'return_date' => $row->return_date,
                'airline' => $row->airline ?? 'UNKNOWN',
                'trip_supervisor' => $row->trip_supervisor,
                'executing_company' => $row->executing_company ?? 'UNKNOWN',
                'departure_point' => 'UNKNOWN',
                'booking_status' => strtoupper((string) $row->booking_status),
                'updated_at' => now(),
                'created_at' => now(),
            ];
            DB::table('programs')->updateOrInsert(
                [
                    'program_name' => $programData['program_name'],
                    'departure_date' => $programData['departure_date'],
                    'return_date' => $programData['return_date'],
                ],
                $programData
            );
            $program = DB::table('programs')
                ->where('program_name', $programData['program_name'])
                ->where('departure_date', $programData['departure_date'])
                ->where('return_date', $programData['return_date'])
                ->first();

            DB::table('hajj_umra_bookings')->updateOrInsert(
                ['id' => $row->id],
                [
                    'customer_id' => $customer->id,
                    'program_id' => $program->id,
                    'module' => 'HAJJ_UMRA',
                    'companion_customer_id' => null,
                    'purchase_price' => $row->purchase_price,
                    'selling_price' => $row->selling_price,
                    'profit' => bcsub((string) $row->selling_price, (string) $row->purchase_price, 2),
                    'currency' => 'EGP',
                    'per_person' => true,
                    'status' => match ($row->booking_status) {
                        'pending' => 'PENDING',
                        'confirmed' => 'CONFIRMED',
                        'cancelled' => 'CANCELLED',
                        default => 'CONFIRMED',
                    },
                    'agent_name' => $row->executor ?? $row->trip_supervisor ?? 'migration',
                    'notes' => trim((string) ($row->employee_notes . ' ' . $row->payment_notes)),
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]
            );

            if (Schema::hasTable('hajj_umra_payments') && (float) $row->amount_paid > 0) {
                DB::table('hajj_umra_payments')->insert([
                    'hajj_umra_booking_id' => $row->id,
                    'payment_method' => $this->normalizePaymentMethod($row->payment_method),
                    'amount' => $row->amount_paid,
                    'currency' => 'EGP',
                    'treasury_account' => 'office_drawer',
                    'transaction_reference' => $row->payment_reference,
                    'payment_date' => $row->created_at,
                    'paid_by' => $row->client_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function migrateVisas(): void
    {
        if (!Schema::hasTable('visas') || !Schema::hasTable('visa_bookings')) {
            return;
        }

        $rows = DB::table('visas')->orderBy('id')->get();
        foreach ($rows as $row) {
            DB::table('customers')->updateOrInsert(
                ['phone' => $row->phone],
                [
                    'full_name' => $row->client_name,
                    'passport_number' => $row->passport_number,
                    'date_of_birth' => $row->birth_date,
                    'city' => $row->country,
                    'affiliation' => $row->nationality,
                    'notes' => $row->client_notes,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $customer = DB::table('customers')->where('phone', $row->phone)->first();

            DB::table('visa_details')->insert([
                'visa_type' => strtoupper((string) $row->visa_type),
                'country' => $row->destination_country,
                'duration' => $row->visa_duration,
                'entry_type' => strtoupper((string) $row->entry_type),
                'validity_from' => null,
                'validity_to' => null,
                'executing_company' => $row->agent_company,
                'executing_agent' => $row->agent_name,
                'executing_agent_contact' => null,
                'submission_date' => $row->created_at,
                'expected_result_date' => null,
                'visa_number' => null,
                'status' => strtoupper((string) $row->visa_status),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $visaDetailId = DB::getPdo()->lastInsertId();

            DB::table('visa_bookings')->updateOrInsert(
                ['id' => $row->id],
                [
                    'customer_id' => $customer->id,
                    'visa_detail_id' => $visaDetailId,
                    'module' => 'VISA',
                    'purchase_price' => $row->purchase_price,
                    'selling_price' => $row->selling_price,
                    'service_fee' => 0,
                    'profit' => bcsub((string) $row->selling_price, (string) $row->purchase_price, 2),
                    'currency' => 'EGP',
                    'status' => match ($row->visa_status) {
                        'pending' => 'PENDING',
                        'submitted' => 'SUBMITTED',
                        'approved' => 'APPROVED',
                        'rejected' => 'REJECTED',
                        'cancelled' => 'CANCELLED',
                        default => 'PENDING',
                    },
                    'agent_name' => $row->agent_name,
                    'notes' => trim((string) ($row->employee_notes . ' ' . $row->visa_notes)),
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]
            );

            if (Schema::hasTable('visa_payments') && (float) $row->amount_paid > 0) {
                DB::table('visa_payments')->insert([
                    'visa_booking_id' => $row->id,
                    'payment_method' => $this->normalizePaymentMethod($row->payment_method),
                    'amount' => $row->amount_paid,
                    'currency' => 'EGP',
                    'treasury_account' => 'office_drawer',
                    'transaction_reference' => $row->payment_reference,
                    'payment_date' => $row->created_at,
                    'paid_by' => $row->client_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function assertCounts(): void
    {
        if (Schema::hasTable('hajj_umrah')) {
            $source = DB::table('hajj_umrah')->count();
            $target = DB::table('hajj_umra_bookings')->where('module', 'HAJJ_UMRA')->count();
            if ($target < $source) {
                throw new RuntimeException('Hajj Umrah consolidation failed: target count mismatch.');
            }
        }

        if (Schema::hasTable('visas')) {
            $source = DB::table('visas')->count();
            $target = DB::table('visa_bookings')->where('module', 'VISA')->count();
            if ($target < $source) {
                throw new RuntimeException('Visa consolidation failed: target count mismatch.');
            }
        }
    }

    private function normalizePaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash_egp', 'cash', null => 'cash',
            'bank_transfer', 'bank_deposit' => 'bank_transfer',
            'cash_wallet', 'mobile_money', 'cash_yasser', 'post_office' => 'cash_wallet',
            'office_safe' => 'office_safe',
            'office_drawer' => 'office_drawer',
            default => 'cash',
        };
    }
};
