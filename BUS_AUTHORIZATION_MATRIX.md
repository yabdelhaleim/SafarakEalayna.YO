# BUS AUTHORIZATION MATRIX REPORT

Evaluation of role-based security and middleware enforcement across endpoints.

| Endpoint | Unauthenticated | Normal User | Admin User | Middleware Enforced |
| --- | --- | --- | --- | --- |
| `GET /api/v1/public/bus/companies` | Allowed (200) | Allowed (200) | Allowed (200) | Public (`api`) |
| `GET /api/v1/bus/companies` | Blocked (401) | Allowed (200) | Allowed (200) | `auth:sanctum` |
| `POST /api/v1/bus/companies/{id}/pay-debt` | Blocked (401) | Blocked (403) | Allowed (200/422) | `admin` |
| `POST /api/v1/bus/bookings/{id}/cancel` | Blocked (401) | Blocked (403) | Allowed (200) | `admin` |
| `POST /api/v1/bus/refunds/{id}/process` | Blocked (401) | Blocked (403) | Allowed (200) | `admin` |
