# BUS PHASE 6 STATE MACHINE AUDIT

State transition matrix evaluation (`pending`, `paid`, `cancelled`, `refunded`, `partially_refunded`).

* **Forbidden State Transition: Pay Cancelled Booking Rejected**: **PASS** — Response status: HTTP 422
