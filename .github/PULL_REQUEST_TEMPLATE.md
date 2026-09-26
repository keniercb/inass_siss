## What does this PR implement?

<!-- Reference the requirement/plan item, e.g. RF-SEG-001, S1.4, H-06 -->

- Plan item:
- Requirement(s):
- Open question touched (if any):

## Verification checklist

- [ ] New behavior is covered by tests (TDD: red -> green).
- [ ] `vendor/bin/pint --test` passes.
- [ ] `vendor/bin/phpstan analyse` passes (level 8).
- [ ] `vendor/bin/deptrac analyse` passes (module boundaries respected).
- [ ] `vendor/bin/pest --coverage` passes (global >= 80 %, domain >= 90 %).
- [ ] Feature tests run against real MySQL (no SQLite, ADR-08).
- [ ] No money/decimal logic uses floats (RNF-008).
- [ ] No hardcoded policy parameters (versioned settings only, RN-007).
- [ ] Documentation updated when the change alters documented behavior.

## Notes for reviewers

<!-- Edge cases, dataset decisions, follow-ups -->
