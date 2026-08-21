---
description: Answer what is actually live on production right now, and what is not
---

Answer, with evidence rather than from memory: **what is running on the box, and what
finished work has not reached it?**

1. `git fetch origin --tags`, then compare: local `HEAD`, `origin/main`, the newest tag.
2. Read the live build without SSH:
   `curl -s https://<apex>/health` for the version, and with `X-Health-Secret` for the
   exact image tag. Take the apex from `.claude/OPS.local.md`, never from a literal.
3. List the commits on `main` that are **newer** than the deployed release, and say what
   user-visible behaviour each one changes.
4. List every open pull request with its check status. Name any that is green — that is
   finished work nobody is shipping.
5. Run `bin/smoke <apex>` and report the result verbatim.

Then state plainly, in one paragraph: is the site serving the newest work? If not, what is
missing and what is the one command that fixes it.

Do not report "deployed" for anything `bin/smoke` did not confirm.
