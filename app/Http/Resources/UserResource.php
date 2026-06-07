<?php

namespace App\Http\Resources;

use App\Support\UserPermissions;
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
            'permissions' => UserPermissions::effectiveFor($this->resource),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'salary' => $this->employee->salary,
                'status' => $this->employee->status,
            ] : null),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
