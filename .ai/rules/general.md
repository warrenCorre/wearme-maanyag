---
paths:
  - '**/*'
---

# General

## Owner performs live browser verification
For each approved application slice, make the change testable in the Owner's running local browser and provide short manual check steps; the Owner performs and reports live browser verification. The agent runs automated tests only with fictional/synthetic data and mocked provider calls; never use real Gemini or customer data for its tests, and never claim a browser pass the Owner has not reported. Do not change the Gemini-sharing flag for testing; respect the unpaid-quota privacy boundary.

## Assistant commits slices; owner pushes
After each approved, verified code slice, the assistant stages and commits that slice locally and reports its hash and message. Only the Owner pushes or performs GitHub actions; the assistant must never push on the Owner’s behalf.
