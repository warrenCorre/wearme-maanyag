---
paths:
  - '**/*'
---

# General

## Owner performs independent browser verification
The Owner independently checks application changes in their local browser and reports their sign-off. Agent-observed checks on fictional/DEMO data are separate and must not be presented as the Owner's browser pass. Preserve the unpaid-quota privacy boundary: never use real Gemini or customer data for agent tests, and never change the Gemini-sharing flag just to test.

## Assistant commits slices; owner pushes
After each approved, verified code slice, the assistant stages and commits that slice locally and reports its hash and message. Only the Owner pushes or performs GitHub actions; the assistant must never push on the Owner’s behalf.

## Agent browser checks before application slice commits
For every approved application change or slice, run relevant automated tests and an agent-observed local browser check of the affected workflow with fictional/DEMO data before committing. Report what the browser actually showed separately from the Owner’s independent sign-off; never claim a pass when browser access fails. If the required browser check is blocked, do not call the slice verified or commit it; explain the blocker. Do not use real customer data, live Gemini, or change sharing flags. For documentation/rule-only changes with no browser behavior, explicitly say a browser scenario is not applicable.
