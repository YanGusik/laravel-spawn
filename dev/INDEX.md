# dev/

Working notes for the async adaptation: what was reviewed, what was measured, and what is
owed to another project. The package's own documentation lives at the repository root —
`ASYNC_ADAPTATION.md` for what is adapted and how, `ASYNC_KNOWN_ISSUES.md` for what the design
cannot do, `CHANGELOG.md` for what changed.

| Path | What it holds |
|---|---|
| `reviews/` | Reviews of a change, kept because the reasoning outlives the diff |
| `measurements/` | Scripts that produced a number quoted elsewhere, so the number can be re-taken |
| `upstream/` | Reports owed to another project, drafted here before they are sent |

## Reviews on record

| File | Subject |
|---|---|
| `reviews/2026-08-30-reproducers.md` | The four reproducers for #65: what a passing run was allowed to mean |
| `reviews/2026-08-30-eloquent-statics.md` | The guard and event windows: the missed read, the drift trap, what was verified by running |
| `reviews/2026-08-30-rate-limiter.md` | Whether the throttling overshoot is this package's to fix, with the fan-out numbers |

Reproducers of a defect are not here: they live beside the code they exercise, under
`tests/proof/`, and each one runs as `php tests/proof/<name>.php`.
