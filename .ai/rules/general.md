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

## Agent browser verification must be visible on the Owner’s screen
For every required agent browser check, bring the test browser window to the foreground on the Owner’s screen and keep the affected workflow visibly displayed while testing; a background CDP tab or DOM-only inspection is not enough. Confirm the foreground view yourself and report exactly what appeared, separately from the Owner’s independent sign-off. If a visible foreground check cannot be performed, report the blocker and do not claim verification or commit an unverified application slice. Existing DEMO-only, no-live-Gemini and no-sharing-flag-change boundaries still apply.
