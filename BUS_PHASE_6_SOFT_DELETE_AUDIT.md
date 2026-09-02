# BUS PHASE 6 SOFT DELETE AUDIT

* **Inventory Creation on Soft-Deleted Company Validation Boundary Check**: **FINDING** — Finding: StoreBusInventoryRequest validates company_id using 'exists:bus_companies,id' without whereNull('deleted_at'). HTTP status: 201.
