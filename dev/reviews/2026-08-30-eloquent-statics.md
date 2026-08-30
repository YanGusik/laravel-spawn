# Review of the Eloquent statics fix

**Date:** 2026-08-30 · **Subject:** commit `62dd8b0` · **Reviewers:** one code review, one adversarial verification

Two reviews ran against the same commit. Both found the same missed read and the same
operational risk, and between them they ran the suite, the reproducer, PHPStan, four probes
and a benchmark.

## Blocking, and repaired in `a099fb7`

**`getGuarded()` still read the static.** Every other read went through the window; this one
did not, so inside `Model::unguarded()` the copy answered `['*']` where Laravel answers `[]`,
and `totallyGuarded()` and `isGuarded()` followed it. Laravel's own internals never notice —
`fill()` consults `totallyGuarded()` only in a branch `isFillable()` cannot reach under a
window — which is why the suite stayed green and the copy was quietly inconsistent about the
one invariant it exists for.

**The refresh tool handled half of what the drift alarm fires on.** The nightly job checks all
four copies, and `bin/refresh-eloquent-overrides.php` knew two. The fastest way out for
whoever is on call would have been to paste the new checksum in, which re-enables a copy
without its edits — a green alarm over a reverted fix. The tool now rewrites all four and
reproduces the two new copies byte for byte, which is how the edits are known to be described
correctly.

**Installing was all-or-nothing.** A Laravel release touching `HasEvents` dropped all four
registrations and brought back the relation defect through a file that has nothing to do with
it. The copies now install in three independent groups, and `src/bootstrap.php` raises an
`E_USER_WARNING` naming the group it left out instead of discarding the return value.

## Verified sound, by running

- A child coroutine — `Async\spawn()` or `Scope::inherit()` from inside a window — does not
  see it. That is the intended reading of a context-local window, and it falls the safe way
  for the guard (a child fills guarded) and the loud way for events (a child's observers
  fire). Now stated in both docblocks and in `ASYNC_KNOWN_ISSUES.md` item 12.
- A coroutine killed inside a window leaves nothing behind: the next coroutine is guarded and
  the static is untouched.
- Restoration on a throw, value transit, nested windows, and the process-wide `unguard()` and
  `setEventDispatcher()` all behave as intended.
- The eight replaced reads of `static::$dispatcher` in `HasEvents` change no behaviour;
  `NullDispatcher` forwards `listen()` and `forget()`, so window and static agree.
- Nothing in the framework or the installed packages caches `Model::isUnguarded()` or
  `getEventDispatcher()` across a suspension, which would defeat the window.
- Checksums of the four vendor files match the constants.

## Measured

`isFillable()` and `fillableFromArray()` do a context lookup where they read a static.
`Model::fill()` with eight fillable attributes, 200k iterations: about 8% slower on a loop that
does nothing but fill, and invisible against a request that touches a database.

## Noted, not repaired

Nothing pins the independence of the groups. It would take a Laravel file that has moved, which
no fixture stands in for, so the group a release breaks is found by the nightly drift job
rather than by a test. Recorded in `ASYNC_KNOWN_ISSUES.md` item 12.

Restoring a window writes the previous value back rather than removing the entry, so a null
previous leaves a null entry. Every read is `findLocal()`, which answers null either way; a
read through `find()` would see that entry shadow an enclosing scope's value. Both windows say
so at the restore.
