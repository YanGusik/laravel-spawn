# Review of the reproducers for #65

**Date:** 2026-08-30 · **Subject:** `tests/proof/` at commit `2f14080` · **Reviewer:** adversarial read, then a rewrite

The four reproducers were written to prove the defects reported in #65 before anything was
fixed. The review attacked the proofs rather than the code, and most of what it found was
about what a passing run would mean.

## Accepted as it stood

`prove_unguarded` — the mechanism matches the framework source: `GuardsAttributes::unguarded`
sets the static and restores it in a `finally`, `HasEvents::withoutEvents` installs a
`NullDispatcher` and restores it the same way, and `src/` handled neither. The sequential
control runs the same two delays and reads correctly; the concurrent run differs only in
overlap.

## Found and repaired

**One verdict could not tell a caught defect from an empty run.** Every script aggregated
controls and defect checks into one boolean, so a run in which no request executed at all
exited 1 — "defect reproduced" — exactly as a run that caught the defect. Repaired by
splitting the tally: a failed control makes the run inconclusive and exits 2, and each script
carries a check that must pass for the run to mean anything. Verified by removing the body of
the concurrent request, which now makes all four exit 2.

**The rate limiter's stub manufactured a second race.** `ArrayStore::increment()` is a read
and a write, so a stub that delayed inside both suspended between its own read and its own
write — a lost update that Redis `INCR` and Memcached do not have. Repaired: the round trip is
paid once per store call and the operation runs with nothing suspending inside it, and `add()`
is implemented as a check-and-set rather than falling back to the repository's read-then-write.

**The ownership claim rested on a run that could not fail.** A hand-interleaved run was
presented as evidence about FPM workers, where it establishes only that `tooManyAttempts()`
has no side effect. Demoted to a control, and the ownership argument now cites
`RateLimiter.php:203` against `:161` instead.

**The HTTP oracle could be set by five unrelated failures, all in the safe direction.**
Catching any `Throwable` and reading it as "isolation held" meant an unresolvable host, a
factory that would not build and a stray-request guard all reported the same thing. Repaired:
the defect is recognised by a positive marker — the body request A stubbed, arriving in
request B — and request B refuses to leave the process, so nothing waits out a DNS timeout.

**Two "concurrent" runs were second sequential runs on poisoned state.** Neither the HTTP nor
the log scenario suspends, and both read what the previous run had installed. Repaired: each
run boots a worker of its own.

**The log probe read the last record of a shared handler.** One suspension between the two
writes and it would have reported the other request's line. Repaired: the record is found by
its message.

**`prove_unguarded` could pass for the wrong reason.** Its interleave was timing-driven, so a
loaded machine could let B's probe slip past A's window and print four `[ok]` lines. Repaired:
B records whether A was inside its callback, as a control, so a missed interleave is
inconclusive rather than a quiet success.

## Left as they are

The three windows (`RelationWindow`, `GuardWindow`, `EventDispatcherWindow`) share a shape but
not a type: a common base would give up the `bool` / `?Dispatcher` typing that makes the two
readable, and PHP has no generics to give it back.
