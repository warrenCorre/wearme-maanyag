---
paths:
  - '**/*'
---

# General

## Owner performs independent browser verification
The Owner independently checks application changes in their local browser and reports their sign-off. Agent-observed checks are separate and must not be presented as the Owner's browser pass. Never expose customer or individual-sale details in test prompts or change the Gemini-sharing flag just to test.

## Assistant commits slices; owner pushes
After each approved, verified code slice, the assistant stages and commits that slice locally and reports its hash and message. Only the Owner pushes or performs GitHub actions; the assistant must never push on the Owner’s behalf.

## Agent browser checks before application slice commits
For every approved application change or slice, run relevant automated tests and an agent-observed local browser check of the affected workflow before committing. For Assistant changes, use the normal app at `http://127.0.0.1:8000`, live Gemini and its configured real database with fictional, non-identifying prompts. Store checks may read already-authorized aggregate facts; never expose customer or individual-sale details, write test records, or change sharing flags. Report exactly what the live app showed separately from Owner sign-off. If browser access fails, do not claim verification or commit the application slice. For documentation/rule-only changes with no browser behavior, explain why no browser check applies.

## Agent browser verification must be visible on the Owner’s screen
For every required agent browser check, bring the test browser window to the foreground on the Owner’s screen and keep the affected workflow visibly displayed while testing; a background CDP tab or DOM-only inspection is not enough. Confirm the foreground view yourself and report exactly what appeared, separately from the Owner’s independent sign-off. If a visible foreground check cannot be performed, report the blocker and do not claim verification or commit an unverified application slice.

## Use Conventional Commit messages
Use `type(scope): concise description` for every new local commit. Choose a fitting type such as `feat`, `fix`, `docs`, `refactor`, `test`, or `chore`, and a meaningful scope such as `assistant` or `dashboard`; use `feat` only for a feature.
