---
description: Verify, tag, publish and deploy one release, then prove it is live
---

Release what is on `main` — or, if `$ARGUMENTS` names a pull request or a change, finish
that first and then release it.

Follow `docs/RELEASE_PROCESS.md` exactly. Do not improvise a deploy path; `bin/deploy` is
the one script whose failure mode is an outage.

1. Read `CLAUDE.md`, `docs/RELEASE_PROCESS.md` and `docs/VERSIONING.md`.
2. Confirm the worktree holds only intended changes.
3. If a pull request is still open: read its checks. Green ⇒ merge it
   (`gh pr merge --squash --delete-branch`) and pull `main`. Red ⇒ that is work to do,
   not a question to ask.
4. Confirm `VERSION` is the right next number per `docs/VERSIONING.md`, and that
   `CHANGELOG.md` has an entry for it that explains **why**, not what the diff shows.
   Both belong to the pull request that changed the behaviour — if they are missing, they
   go in a commit on a branch, not straight onto `main`.
5. Run `bin/release --deploy`. Read its output; it refuses for reasons.
6. Report the deployed version, the image tag, and the smoke result — the actual output,
   not a summary of it. If smoke failed, the release is **not** done: roll back or roll
   forward, and say which.
7. Append one line to `docs/PROGRESS.md`. Tick the roadmap box only now.

Never deploy when CI is red on the exact commit, when the tag is missing, when the
changelog entry is missing, or when a green pull request is still sitting open.

Never print, commit or copy a secret or a hostname. Production coordinates are local-only
(`.claude/OPS.local.md`, `.deploy.local`) and the repository is public.
