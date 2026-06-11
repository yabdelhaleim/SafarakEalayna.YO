<?php

namespace Tests\Unit\Employee;

use App\Models\Employee;
use App\Models\Employee\EmployeeBonus;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\Employee\EmployeeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Employee $employee;
    protected EmployeeReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Employee Report Tester',
            'email' => 'emp-report-tester@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'user_id' => $this->user->id,
            'position' => 'Developer',
            'department' => 'IT',
            'status' => 'active',
            'performance_rating' => 'excellent',
            'salary' => 10000.00,
        ]);

        $this->service = app(EmployeeReportService::class);
        Sanctum::actingAs($this->user, ['*']);
    }

    /**
     * Test getOverallReport math calculations (inactive employee count, attendance rate).
     */
    public function test_get_overall_report_math(): void
    {
        // Add attendances: 3 present, 1 late, 1 absent = 5 total
        // Attendance rate = 3 / 5 = 60.00%
        $today = now()->toDateString();
        $fiveDaysAgo = now()->subDays(5)->toDateString();

        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(1)->toDateString(), 'status' => 'present']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(2)->toDateString(), 'status' => 'present']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(3)->toDateString(), 'status' => 'present']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(4)->toDateString(), 'status' => 'late']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(5)->toDateString(), 'status' => 'absent']);

        // Add bonus & deduction within query range
        $bonus = EmployeeBonus::query()->create([
            'employee_id' => $this->employee->id,
            'type' => 'bonus',
            'amount' => 500.00,
            'reason' => 'Good work',
            'created_by' => $this->user->id,
        ]);
        $bonus->created_at = now()->subDays(2);
        $bonus->save();

        $deduction = EmployeeBonus::query()->create([
            'employee_id' => $this->employee->id,
            'type' => 'deduction',
            'amount' => -200.00,
            'reason' => 'Late',
            'created_by' => $this->user->id,
        ]);
        $deduction->created_at = now()->subDays(2);
        $deduction->save();

        $tomorrow = now()->addDay()->toDateString();
        $report = $this->service->getOverallReport($fiveDaysAgo, $tomorrow);

        // Inactive employees: 1 total (active), 0 inactive
        $this->assertEquals(1, $report['summary']['total_employees']);
        $this->assertEquals(1, $report['summary']['active_employees']);
        $this->assertEquals(0, $report['summary']['inactive_employees']);

        // Financials: 500 bonus, 200 deduction, 300 net
        $this->assertEquals(500.00, (float) $report['financials']['total_bonuses']);
        $this->assertEquals(200.00, (float) $report['financials']['total_deductions']);
        $this->assertEquals(300.00, (float) $report['financials']['net_amount']);

        // Attendance rate: 3 present out of 5 total = 60.00%
        $this->assertEquals(60.00, (float) $report['attendance']['attendance_rate']);
    }

    /**
     * Test getEmployeePerformanceReport math calculations (attendance rate, punctuality rate).
     */
    public function test_get_employee_performance_report_math(): void
    {
        $today = now()->toDateString();
        $fiveDaysAgo = now()->subDays(5)->toDateString();

        // 3 present, 1 late, 1 absent = 5 total
        // Attendance rate = 3 / 5 = 60.00%
        // Punctuality rate = (5 - 1 late) / 5 = 4 / 5 = 80.00%
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(1)->toDateString(), 'status' => 'present']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(2)->toDateString(), 'status' => 'present']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(3)->toDateString(), 'status' => 'present']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(4)->toDateString(), 'status' => 'late']);
        EmployeeAttendance::query()->create(['employee_id' => $this->employee->id, 'attendance_date' => now()->subDays(5)->toDateString(), 'status' => 'absent']);

        // Add bonus & deduction within query range
        $bonus = EmployeeBonus::query()->create([
            'employee_id' => $this->employee->id,
            'type' => 'bonus',
            'amount' => 500.00,
            'reason' => 'Good work',
            'created_by' => $this->user->id,
        ]);
        $bonus->created_at = now()->subDays(2);
        $bonus->save();

        $deduction = EmployeeBonus::query()->create([
            'employee_id' => $this->employee->id,
            'type' => 'deduction',
            'amount' => -200.00,
            'reason' => 'Late',
            'created_by' => $this->user->id,
        ]);
        $deduction->created_at = now()->subDays(2);
        $deduction->save();

        $tomorrow = now()->addDay()->toDateString();
        $report = $this->service->getEmployeePerformanceReport($this->employee->id, $fiveDaysAgo, $tomorrow);

        $this->assertEquals(60.00, (float) $report['performance_metrics']['attendance_rate']);
        $this->assertEquals(80.00, (float) $report['performance_metrics']['punctuality_rate']);
        $this->assertEquals(500.00, (float) $report['performance_metrics']['total_bonuses']);
        $this->assertEquals(200.00, (float) $report['performance_metrics']['total_deductions']);
        $this->assertEquals(300.00, (float) $report['performance_metrics']['net_bonuses']);
    }
}
