<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'permissions' => $this->getUserPermissions(),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'salary' => $this->employee->salary,
                'status' => $this->employee->status,
            ] : null),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    /**
     * الحصول على صلاحيات المستخدم
     */
    private function getUserPermissions(): array
    {
        // Return dynamic database permissions if specified by admin
        $dbPermissions = is_array($this->permissions) ? $this->permissions : [];
        if (!empty($dbPermissions)) {
            return $dbPermissions;
        }

        $permissions = [];

        switch ($this->role) {
            case 'admin':
                $permissions = [
                    'view_dashboard', 'manage_flights', 'manage_bus', 'manage_hajj',
                    'manage_online', 'manage_treasury', 'manage_employees',
                ];
                break;

            case 'owner':
                $permissions = [
                    'view_dashboard', 'manage_flights', 'manage_bus', 'manage_hajj',
                    'manage_online', 'manage_treasury', 'manage_employees',
                ];
                break;

            case 'employee':
                $permissions = [];
                break;
        }

        return $permissions;
    }
}
