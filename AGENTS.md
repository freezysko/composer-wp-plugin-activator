# AGENTS.md

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

## 5. Subagent execution protocol

When dispatched to implement a task from the implementation plan, subagents follow the protocol below.

### Lifecycle

Each subtask is sized ~2 hours of focused work with a single, verifiable acceptance criterion.

1. **Pick.** Subagent picks the next unchecked `[ ]` subtask in the active plan, in order, that has no unresolved blocker.
2. **Implement.** Subagent works on a local branch named `feat/<n>-<slug>` / `fix/<n>-<slug>` / `chore/<n>-<slug>` / `docs/<n>-<slug>` where `<n>` is the subtask identifier. Commits are conventional, signed, and end with the trailer `Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>` (use `git commit ... --trailer 'Co-Authored-By: …'` or include it in the commit-message body so authorship of AI-driven work is recorded).
3. **Self-verify.** Subagent runs `composer ci` (and any task-specific extra checks) locally. All must be green.
4. **Review chain (hybrid granularity):**
   - **Per-subtask:** Codex review via the `codex:codex-rescue` subagent. Receives the diff (`git diff main..<branch>`), the relevant task context, and `AGENTS.md`. Returns review text.
   - **Per-task closure:** a fresh Claude review (general-purpose subagent without parent context) takes the cumulative diff plus context for a systemic check.
5. **Route:**
   - Per-subtask Codex green → owner glances the diff and fast-forward-merges onto `main` locally, then pushes.
   - Per-subtask Codex flags → implementer reworks → re-review (loop until green or escalated to owner).
   - Per-task Claude review flags → either (a) immediate follow-up subtask to fix, or (b) deferred to a later patch release. Owner decides.
6. **Bookkeeping.** After `main` is pushed, subagent marks the subtask done in the plan.

### No-PR window

Until the first public release, subagents do **not** open GitHub PRs. Diffs are reviewed locally; the owner fast-forwards onto `main` and pushes. The only exceptions are bot PRs (Dependabot, release-please) and one-off temporary PRs needed to exercise PR-only workflows (e.g. `dependency-review-action`). Post-release, branch protection becomes fully enforcing and all work goes through PRs.

### Parallelism

Serial by default. The active plan may explicitly mark subtasks as parallel-safe. Owner authorizes parallel dispatch on a per-batch basis.
