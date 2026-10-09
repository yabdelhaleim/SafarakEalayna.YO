# BUS P2 REGRESSION RESULTS

| Test Case | Expected HTTP | Actual HTTP | Status | Notes |
| --- | --- | --- | --- | --- |
| 1. Create inventory with active company | `201` | `201` | **PASS** |  |
| 2. Create inventory with soft-deleted company | `422` | `422` | **PASS** | P2 Vulnerability Fixed! Soft-deleted company rejected. |
| 3. Create inventory with nonexistent company | `422` | `422` | **PASS** |  |
| 4. Update inventory to active company values | `200` | `200` | **PASS** |  |
| 5. Update inventory attempting company_id change to soft-deleted company | `422` | `422` | **PASS** | Forbidden field company_id rejected during update. |
| 6. Existing inventory of historical soft-deleted company remains intact | `200` | `200` | **PASS** | Historical inventory preserved in DB. |
| 7. Public available inventories rejects soft-deleted company | `422` | `422` | **PASS** | Public endpoint rejects inactive/soft-deleted companies. |
| 8. Booking against valid active inventory | `201` | `201` | **PASS** |  |
| 9. Booking against historical inventory safely handled | `201` | `201` | **PASS** |  |
