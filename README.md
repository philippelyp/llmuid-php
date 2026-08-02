# LLMUID for PHP

> Identifiers that resist hallucination, survive repeated LLM copying, and
> repair themselves when damaged — or fail honestly when they can't.

The reference implementation of [LLMUID][spec], an identifier scheme for
systems where identifiers must pass through large language models — read,
copied and re-emitted across many prompt hops.

```
K7-M3-XR-9D-Q2
```

Ten symbols over a 29-symbol alphabet of digits and consonants, eight of them a
random payload and two of them check symbols, written as five groups of two. No
vowels, so an identifier can never spell a word. No lookalikes, so it can never
be misread across ambiguous glyphs.

## Install

```sh
composer require philippelyp/llmuid
```

Requires PHP 8.2 or later.

## Use

```php
use LLMUID\LLMUID;

$r = new LLMUID();

$id = $r->mint();                     // K7-M3-XR-9D-Q2
```

Reading is liberal. Case, delimiters and wrapping carry no information, and any
single damage event is repaired silently:

```php
$r->resolve('K7-M3-XR-9D-Q2');        // K7-M3-XR-9D-Q2, pristine
$r->resolve('`k7 m3 xr 9d q2`');      // K7-M3-XR-9D-Q2, delimiters and case
$r->resolve('K7-M3-XB-9D-Q2');        // K7-M3-XR-9D-Q2, one substitution
$r->resolve('K7-M3-RX-9D-Q2');        // K7-M3-XR-9D-Q2, one transposition
```

Anything further away is a hard failure, never a guess:

```php
$r->resolve('K7-M3-ZZ-ZZ-Q2');        // FALSE
$r->last_error();                     // 'Checksum failed and no issued
                                      //  identifier is within 2 edits'
```

### Context binding

Check symbols can be bound to the slot, role or parent an identifier belongs
to, so a genuine identifier pasted into the wrong place fails to resolve. The
same context string must be given to `mint()` and to `resolve()`.

```php
$id = $r->mint('invoice');

$r->resolve($id, 'invoice');          // the identifier
$r->resolve($id, 'receipt');          // FALSE
$r->last_error();                     // 'Wrong context: ... was not issued
                                      //  under this context'
```

This is the defence against the most dangerous failure of all — a well-formed
identifier in the wrong role, which nothing about the string itself can catch.

### Persisting the registry

The registry is in-memory and append-only: it lives in the object and dies with
it. `registry()` hands the issued set back so a caller can persist it, and the
constructor takes that same array back.

```php
$issued = $r->registry();             // array of canonical renderings

$r = new LLMUID($issued);             // same registry, new process
```

## API

```php
mint(string $context = ''): string|false
resolve(string $llmuid, string $context = ''): string|false
registry(): array
last_error(): string|false
self_test(): bool
```

There are no exceptions. Failure returns `FALSE` and explains itself through
`last_error()`. The one call that can throw — the system random source — is
caught and converted into a failed mint like any other.

The wording of `last_error()` separates the two things worth watching: a repair
means the channel is degrading, while a failure means the pipeline is faulty,
since honest noise almost never produces multi-event damage.

## Conformance

`src/vectors/` is a copy of the conformance vectors from the [specification
repository][spec]: 134 cases pinning the context digest, liberal reading, the
bounded distance and the damage contract end to end. `self_test()` grades this
class against every one of them, and then against the invariants minting is
answerable for — which are random by design, so no fixed case can pin them.

```php
$r->self_test();                      // TRUE
$r->last_error();                     // the first failing case, if not
```

It reads the vectors from the installed package, mints only into throwaway
objects, and leaves the registry of the object it is called on untouched.

The vectors are frozen and they are the answer key. A failure means this
implementation has drifted from the specification — never that a vector needs
updating.

## `resolve()` is not an extractor

Normalization is deliberately liberal, which means it will happily eat the
prose around an identifier as well:

```php
$r->resolve('see invoice K7-M3-XR-9D-Q2 today');   // FALSE, too long
```

Extract identifiers from surrounding text yourself — they match
`/\b[0-9BCDFGHJKMNPQRSTVWXZ]{2}(?:-[0-9BCDFGHJKMNPQRSTVWXZ]{2}){4}\b/` in
canonical rendering — and hand `resolve()` one candidate at a time.

## Not a security mechanism

The random payload makes identifiers statistically unguessable, but not
cryptographically so, and the check symbols are public arithmetic anyone can
compute. **Identifiers must never be used as secrets, capabilities or bearer
tokens**, and possession of a valid identifier must never grant authority. The
adversary in this design is a hallucinating model, not an attacker.

## Development

There is nothing to install — no dependencies, dev or otherwise, and one
self-contained class. There are two checks, and the class carries the second
one itself rather than the tree carrying a test framework to run it:

```sh
php -l src/LLMUID.php

php -r 'require "src/LLMUID.php"; $r = new LLMUID\LLMUID();
        echo $r->self_test() ? "pass\n" : $r->last_error() . "\n";'
```

## The specification wins

[llmuid.md][spec] is the design document and is authoritative; this code
implements it without variation. If the two disagree, this code is wrong.

## License

MIT — see [LICENSE](LICENSE).

[spec]: https://github.com/philippelyp/llmuid
