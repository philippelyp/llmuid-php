# Conformance vectors

> **This is a copy.** The canonical files live in the specification repository
> at <https://github.com/philippelyp/llmuid/tree/main/vectors>. Nothing checks
> it automatically. When the originals change, this copy is refreshed from them
> and all three self tests are re-run.

These four files are the answer key every LLMUID implementation is graded
against. They belong to no single language, which is why they live beside
[the specification][spec] rather than inside any implementation.

[spec]: https://github.com/philippelyp/llmuid/blob/main/llmuid.md

| File | What it pins |
|---|---|
| `check.json` | Payload and context in, the two check symbols out. |
| `normalize.json` | Raw input in, the undelimited symbols out. |
| `distance.json` | Bounded Damerau-Levenshtein distance. |
| `resolve.json` | The damage contract end to end, in four groups. |

## Why they exist

Two implementations of this scheme must agree exactly or they are not the same
scheme. The failure is silent: if a Python port derives the context digest
differently from the PHP one, both mint identifiers that look perfectly
well-formed, and neither can resolve the other's. Nothing crashes and nothing
logs. The vectors turn that into a failing test instead.

Three places are worth naming, because each is somewhere a competent
implementer can land somewhere plausible and wrong:

- **The context digest.** `check.json` pins SHA-256 over the canonicalized
  context, the first eight bytes read as two big-endian 32-bit words, each
  reduced modulo 29. Little-endian, or a different slice, yields check symbols
  that are entirely believable and match nothing.
- **The transposition price.** `distance.json` pins an adjacent transposition
  at one operation. A stock Levenshtein routine prices it at two, which silently
  moves a whole damage class outside the repair radius.
- **Byte-wise case folding.** `normalize.json` ends with U+017F, LATIN SMALL
  LETTER LONG S. PHP's `strtoupper` is byte-wise and leaves it alone, so it is
  dropped as not being in the alphabet. Python's `str.upper()` and JavaScript's
  `toUpperCase()` both turn it into `S`, which *is* in the alphabet — so a port
  that upper-cases a decoded string reads an eleventh symbol and turns a
  pristine identifier into a length failure. Normalize over bytes, or restrict
  case folding to ASCII.

## How to read them

Every file carries `scheme`, `description` and `note` alongside its payload.
`check.json`, `normalize.json` and `distance.json` hold a flat `cases` array.
`resolve.json` holds `groups`, each with the `registry` its expectations assume
— build the object from that registry through the constructor, which admits
identifiers without enforcing separation and without re-verifying their check
symbols.

In `resolve.json`, `expect` is the canonical rendering on success and `null` on
failure, and `error` is the exact failure wording or `null`. Both use `null`
rather than `false` because JSON null is the one spelling of absence every
language agrees on; an implementation is free to return whatever its own
conventions call for, so long as success and failure map onto these.

Assert on the error wording, not only on failure. The specification treats a
repair and a hard failure as two distinct telemetry signals — a repair means
the channel is degrading, a failure means the pipeline is faulty — and the
wording is where that distinction lives.

## They are frozen

These files are generated once, hand-reviewed against the specification, and
then frozen. Regenerating them is a scheme version change with freshly
re-derived guarantees, never a maintenance task.

If a change to an implementation alters an expected value here, the
implementation has drifted from the specification and **the implementation is
what is wrong**.

These files were produced mechanically and then reviewed by hand before being
committed — that review, not the fact that a program produced them, is what
gives them their authority.

All three implementation repositories carry a copy of this directory. Nothing
checks the copies automatically; refreshing them is a documented step whenever
these files change.
