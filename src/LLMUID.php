<?php

/**
 *
 * Copyright (c) 2026 Philippe Paquet.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 */




namespace LLMUID;




//
// Required files
//

// None: the class is self-contained.




//
// LLMUID
//
// Identifiers built to survive being read, copied and re-emitted by a language
// model:
//
//   $r = new LLMUID();
//   $r->mint();                        // K7-M3-XR-9D-Q2
//   $r->resolve('K7-M3-XR-9D-Q2');     // K7-M3-XR-9D-Q2, pristine
//   $r->resolve('`k7 m3 xb 9d q2`');   // K7-M3-XR-9D-Q2, repaired
//   $r->resolve('K7-M3-ZZ-ZZ-Q2');     // FALSE, too far from anything issued
//
// The scheme is specified in llmuid.md, which lives in its own repository at
// https://github.com/philippelyp/llmuid, and is implemented here without
// variation: 10 symbols over a 29-symbol alphabet of digits and consonants, 8
// of them a random payload and 2 of them check symbols, written as five groups
// of two. The specification is authoritative; where the two disagree, this
// file is wrong.
//
// The contract is one line: any single damage event is silently repaired, and
// anything more is a failure. A candidate within Damerau-Levenshtein distance 2
// of exactly one issued identifier is repaired unconditionally; anything
// further away, or ambiguous, is refused rather than guessed at.
//
// Writing is strict -- one canonical rendering -- and reading is liberal: case,
// delimiters and wrapping carry no information and are discarded before
// anything is judged.
//
// Minting keeps every pair of issued identifiers more than 4 edits apart, which
// is twice the repair radius, so no damage event can ever carry a candidate out
// of its own identifier's radius or into another's. Mis-repair is ruled out by
// construction rather than by probability.
//
// The check symbols can be bound to a context -- a slot, a role, a parent -- so
// that a genuine identifier pasted into the wrong place fails to resolve. The
// same context string must be given to mint() and to resolve().
//
// The registry is in-memory and append-only: it lives in the object and dies
// with it. registry() hands the issued set back so a caller can persist it, and
// the constructor takes that same array back.
//
// No exceptions: failure returns FALSE and explains itself through
// last_error().
//
// self_test() grades this class against the conformance vectors that ship
// beside it, so that "this implements the specification" is something an
// installation can check rather than take on trust.
//
// mint(string $context = ''): string|false
// resolve(string $llmuid, string $context = ''): string|false
// registry(): array
// last_error(): string|false
// self_test(): bool
//

class LLMUID
{
	//
	// Scheme version 1
	//
	// These parameters form one versioned unit. Changing any of them -- the
	// alphabet, the length, the split, the grouping, the radius -- is a new
	// scheme version with freshly re-derived guarantees, never a configuration
	// option. In particular the alphabet size is prime, which is what makes the
	// check arithmetic ordinary modular arithmetic, and the group size is
	// aligned to model tokenization, which is what makes one damage event
	// damage one group.
	//

	private const ALPHABET = '0123456789BCDFGHJKMNPQRSTVWXZ';

	private const BASE = 29;

	private const LENGTH  = 10;
	private const PAYLOAD = 8;

	private const GROUP     = 2;
	private const DELIMITER = '-';

	//
	// One damage event costs at most 2 edit operations, so the radius within
	// which a candidate is repaired is 2, and the separation minting enforces
	// between issued identifiers is twice that.
	//

	private const RADIUS     = 2;
	private const SEPARATION = 4;

	//
	// How far from canonical length a candidate may be and still be worth
	// repairing: one token's worth of symbols inserted or dropped.
	//

	private const WINDOW = 2;

	//
	// Redraws mint() attempts before giving up. A draw is only ever rejected by
	// the separation check, which in a space of 29^8 fires so rarely that a
	// hundred consecutive rejections is not a full registry, it is a broken
	// random source -- and no number of further attempts fixes that.
	//

	private const MINT_ATTEMPTS = 100;


	//
	// Variables
	//

	private string|false $last_error = FALSE;

	//
	// The registry, keyed by the undelimited symbols so that membership is a
	// single lookup, holding the canonical rendering. Insertion order is mint
	// order, which is what registry() hands back.
	//

	private array $registry = array();

	//
	// The scan accelerator, keyed the same way: a 29-bit mask of the symbols
	// present in an identifier, and how many of its 10 slots are repeats.
	// Together they bound the distance to a candidate from below without
	// touching the distance matrix.
	//

	private array $mask       = array();
	private array $duplicates = array();

	//
	// Population counts for a 16-bit half word, built once on first use.
	//

	private array $popcount = array();




	//
	// Constructor
	//
	// $issued seeds the registry with identifiers minted earlier -- the array
	// registry() returned in a previous process. Entries that do not normalize
	// to a full-length identifier are dropped; their check symbols cannot be
	// re-verified here, since the context an identifier was minted under is not
	// part of it.
	//

	function __construct(array $issued = array())
	{
		foreach ($issued as $identifier) {
			if (TRUE === is_string($identifier)) {
				$symbols = $this->_normalize($identifier);

				if (self::LENGTH === strlen($symbols)) {
					$this->_register($symbols);
				}
			}
		}
	}




	//
	// mint
	//
	// Draw a new identifier, register it and return it in canonical rendering.
	//
	// $context binds the check symbols to the slot, role or parent the
	// identifier belongs to. The same string must be handed to resolve(), which
	// is what makes a valid identifier in the wrong place detectable. The empty
	// context is a context like any other, and the default.
	//
	// FALSE is returned when the draw could not be separated from the issued
	// set within the attempt limit, which at any sane registry size means the
	// random source is degenerate rather than the space exhausted, and when the
	// system has no random source to draw from at all.
	//

	function mint(string $context = ''): string|false
	{
		$this->last_error = FALSE;

		$alphabet = self::ALPHABET;

		for ($attempt = 0; $attempt < self::MINT_ATTEMPTS; $attempt++) {
			$payload = '';

			//
			// The draw comes from the system's cryptographic generator rather
			// than mt_rand(): it rejection-samples, so all 29 symbols are
			// equally likely where a modulo would lean on the low ones, and it
			// carries no recoverable state for a reader to extrapolate the next
			// identifier from. Uniformity is what the sparsity argument rests
			// on; unpredictability is a bonus this scheme does not claim.
			//
			// It is also the one call in the class that can throw -- when the
			// operating system has no random source to offer -- and a thrown
			// exception is not something this class does, so it becomes a
			// failed mint like any other.
			//
			try {
				for ($i = 0; $i < self::PAYLOAD; $i++) {
					$payload .= $alphabet[random_int(0, (self::BASE - 1))];
				}
			} catch (\Random\RandomException $exception) {
				$this->last_error = 'The system random source is unavailable: ' . $exception->getMessage();

				return FALSE;
			}

			$symbols = ($payload . $this->_check($payload, $context));

			//
			// Separation is what turns sparsity from an expectation into an
			// invariant: nothing issued may sit within twice the repair radius
			// of anything else issued.
			//
			if (FALSE === isset($this->registry[$symbols])) {
				if (0 === count($this->_near($symbols, self::SEPARATION))) {
					return $this->_register($symbols);
				}
			}
		}

		$this->last_error = 'No identifier could be drawn at the required separation in ' . self::MINT_ATTEMPTS . ' attempts';

		return FALSE;
	}




	//
	// resolve
	//
	// Validate a candidate that has been through a model, and return the
	// identifier it stands for in canonical rendering: the candidate itself
	// when it arrived pristine, the issued identifier it is one damage event
	// away from when it did not.
	//
	// $context must be the context the identifier was minted under.
	//
	// FALSE is returned when the candidate is not an identifier this registry
	// issued, when the damage exceeds one event, when two issued identifiers
	// are equally close, or when the identifier is genuine but was resolved
	// under the wrong context. last_error() says which.
	//

	function resolve(string $llmuid, string $context = ''): string|false
	{
		$this->last_error = FALSE;

		//
		// Layer 1. Normalization. Case, delimiters, wrapping and any prose that
		// came along are not part of the identifier, so they are gone before
		// anything is judged. Nothing below may depend on them.
		//
		$symbols = $this->_normalize($llmuid);
		$length  = strlen($symbols);

		//
		// Layer 2. Length routing. The length says which damage occurred, and
		// routes on it: at canonical length the checksum is meaningful, within
		// a token's worth of it the symbols have shifted and only the registry
		// can help, and beyond that the damage is past the single-event budget
		// by construction.
		//
		if ($length > (self::LENGTH + self::WINDOW)) {
			$this->last_error = 'Too long to be one damage event: ' . $length . ' symbols, expected ' . self::LENGTH;

			return FALSE;
		}

		if ($length < (self::LENGTH - self::WINDOW)) {
			$this->last_error = 'Too short to be one damage event: ' . $length . ' symbols, expected ' . self::LENGTH;

			return FALSE;
		}

		//
		// Layer 3. Checksum, at canonical length only. A pass confirmed by the
		// registry is the common, pristine case and the only fast accept.
		//
		// Everything else falls through to repair rather than being refused
		// here: a checksum failure means a substitution or a shift, and a
		// checksum pass with no registry entry means a fabrication or a
		// mangling that satisfied the arithmetic by chance -- roughly 1 in 841
		// of them do -- which the registry, the final authority on what exists,
		// is what settles.
		//
		if (self::LENGTH === $length) {
			if (TRUE === $this->_verify($symbols, $context)) {
				if (TRUE === isset($this->registry[$symbols])) {
					return $this->registry[$symbols];
				}

				//
				// Reported as the symbols that were read rather than dressed up
				// in the canonical rendering: nothing issued this, so it is not
				// an identifier and should not be shown as one.
				//
				return $this->_repair($symbols, $context, 'Well-formed but never issued: ' . $symbols);
			}

			return $this->_repair($symbols, $context, 'Checksum failed and no issued identifier is within ' . self::RADIUS . ' edits');
		}

		return $this->_repair($symbols, $context, 'Symbols were inserted or dropped and no issued identifier is within ' . self::RADIUS . ' edits');
	}




	//
	// registry
	//
	// Every identifier issued, in mint order, in canonical rendering. This is
	// the array the constructor takes back, and the only way the issued set
	// outlives the object.
	//

	function registry(): array
	{
		return array_values($this->registry);
	}




	//
	// last_error
	//
	// Why the last call returned FALSE, or FALSE when it did not.
	//
	// The wording separates the two things worth watching: a repair means the
	// channel is degrading, while a failure means the pipeline is faulty, since
	// honest noise almost never produces multi-event damage.
	//

	function last_error(): string|false
	{
		return $this->last_error;
	}




	//
	// self_test
	//
	// Grade this implementation against the conformance vectors in vectors/ and
	// report whether it passes every one of them. FALSE names the first case
	// that failed through last_error().
	//
	// The vectors are the answer key. They belong to the specification rather
	// than to this language, and they are frozen: a failure here means this
	// implementation has drifted from the specification, never that a vector
	// wants updating.
	//
	// The class grades itself rather than the package carrying a test framework
	// to do it, which is what lets a scheme whose whole claim is that two
	// implementations agree exactly ship with no dependencies at all.
	//
	// The vectors cannot reach mint(): it is random by design and no fixed case
	// can pin it, which is why the last section draws identifiers and checks the
	// invariants minting is responsible for instead.
	//
	// Nothing here touches this object beyond the error slot. The three helpers
	// it calls on $this hold no state, and every registry it needs is built on a
	// throwaway instance -- the registry is append-only, so a self-test that
	// minted into its caller would leave no way back.
	//

	function self_test(): bool
	{
		$this->last_error = FALSE;

		if (FALSE === $this->_test_check()) {
			return FALSE;
		}

		if (FALSE === $this->_test_normalize()) {
			return FALSE;
		}

		if (FALSE === $this->_test_distance()) {
			return FALSE;
		}

		if (FALSE === $this->_test_resolve()) {
			return FALSE;
		}

		if (FALSE === $this->_test_scan()) {
			return FALSE;
		}

		return $this->_test_mint();
	}




	//
	// _repair
	//
	// Layer 4, the authoritative one: find the issued identifiers within the
	// repair radius and accept only when there is exactly one.
	//
	// $reason is what to report when nothing is close enough, phrased by the
	// caller so the failure names the damage that routed the candidate here.
	//
	// The re-verification below is easy to leave out and expensive to leave
	// out: a genuine identifier resolved under the wrong context fails the
	// checksum, arrives here, matches itself at distance zero and would be
	// accepted as a repair of itself -- silently undoing the whole point of
	// binding the checksum to a context.
	//

	private function _repair(string $symbols, string $context, string $reason): string|false
	{
		$matches = $this->_near($symbols, self::RADIUS);
		$count   = count($matches);

		if (0 === $count) {
			$this->last_error = $reason;

			return FALSE;
		}

		if (1 < $count) {
			//
			// Not reachable in practice: minting keeps every issued pair more
			// than twice this radius apart, so no candidate should sit inside
			// two radii at once. That separation is measured with _distance(),
			// which is the restricted variant and not a true metric, so this is
			// a bound rather than a theorem -- and if it ever failed to hold the
			// outcome is the refusal below, never a mis-repair.
			//
			// Refused rather than decoded either way: bounded-distance decoding
			// reports ambiguity, it does not pick a winner.
			//
			$this->last_error = 'Ambiguous: ' . $count . ' issued identifiers are within ' . self::RADIUS . ' edits';

			return FALSE;
		}

		$match = $matches[0];

		if (FALSE === $this->_verify($match, $context)) {
			$this->last_error = 'Wrong context: ' . $this->registry[$match] . ' was not issued under this context';

			return FALSE;
		}

		return $this->registry[$match];
	}




	//
	// _near
	//
	// Every issued identifier within $radius edits of $symbols, as a list of
	// registry keys.
	//
	// This is the one scan in the class, shared by minting at the separation
	// radius and by repair at the repair radius, so the accelerator below has a
	// single home.
	//
	// A full distance matrix per registry entry is affordable at a few hundred
	// identifiers and not at ten thousand, so each entry is screened first
	// against an exact lower bound:
	//
	//   distance >= max(length_a, length_b) - overlap
	//
	// where overlap is the size of the largest common sub-multiset of symbols.
	// Every edit changes the symbol multiset by at most one element -- an
	// adjacent transposition changes it not at all -- so an alignment can match
	// at most overlap symbols, and everything unmatched costs an operation.
	//
	// The overlap itself is bounded from above without counting anything: the
	// symbols the two have in common, from the population count of the AND of
	// their masks, plus the repeated slots the smaller of the two can
	// contribute. That is a mask AND, two table lookups and a comparison per
	// entry, and it discards about 95 percent of a random registry before any
	// matrix is built. It can only ever discard an entry that is genuinely out
	// of range: removing it would cost speed and change no answer.
	//

	private function _near(string $symbols, int $radius): array
	{
		$result = array();

		if (0 === count($this->registry)) {
			return $result;
		}

		if (0 === count($this->popcount)) {
			$this->_build_popcount();
		}

		$signature  = $this->_signature($symbols);
		$mask       = $signature[0];
		$duplicates = $signature[1];
		$length     = strlen($symbols);

		$popcount = $this->popcount;

		//
		// Every registry entry is canonical length, _register() being the only
		// writer and both its callers handing it that. So the pair is the same
		// length in every case but a shifted candidate, where the longer of the
		// two is what has to be accounted for -- which makes the span the same
		// for every entry, and this the place to work it out.
		//
		$span = self::LENGTH;

		if ($length > $span) {
			$span = $length;
		}

		foreach ($this->mask as $candidate => $candidate_mask) {
			$common = ($mask & $candidate_mask);
			$slack  = $this->duplicates[$candidate];

			if ($duplicates < $slack) {
				$slack = $duplicates;
			}

			$overlap = ($popcount[($common & 0xFFFF)] + $popcount[($common >> 16)] + $slack);

			if (($span - $overlap) <= $radius) {
				//
				// An all-digit identifier is a canonical decimal integer, so
				// PHP stored its key as an int. Cast it back here, past the
				// screen, so _distance() and the match _repair() hands to
				// _verify() see the symbols they are typed for rather than
				// leaning on coercion -- and so the screen above, which runs on
				// every entry, pays nothing for it.
				//
				$candidate = (string) $candidate;

				if ($this->_distance($symbols, $candidate, $radius) <= $radius) {
					$result[] = $candidate;
				}
			}
		}

		return $result;
	}




	//
	// _distance
	//
	// Damerau-Levenshtein distance, pricing a substitution, an insertion, a
	// deletion and a transposition of adjacent symbols at one operation each --
	// the four damage events, one operation apiece.
	//
	// Written out rather than taken from levenshtein(), which does not price
	// transpositions at all and would put a transposed pair at distance 2 where
	// the damage model puts it at 1.
	//
	// Bounded: a row whose best entry is already past $limit can only get
	// worse, so the walk stops there and reports $limit + 1. Callers only ever
	// ask whether a candidate is inside a radius, so the exact distance beyond
	// it is worth nothing.
	//

	private function _distance(string $a, string $b, int $limit): int
	{
		$length_a = strlen($a);
		$length_b = strlen($b);

		if (abs($length_a - $length_b) > $limit) {
			return ($limit + 1);
		}

		$previous_previous = array();
		$previous          = range(0, $length_b);

		for ($i = 1; $i <= $length_a; $i++) {
			$current    = array_fill(0, ($length_b + 1), 0);
			$current[0] = $i;
			$best       = $i;

			$symbol_a = $a[$i - 1];

			for ($j = 1; $j <= $length_b; $j++) {
				$cost = ($symbol_a === $b[$j - 1]) ? 0 : 1;

				$value = ($previous[$j] + 1);

				if (($current[$j - 1] + 1) < $value) {
					$value = ($current[$j - 1] + 1);
				}

				if (($previous[$j - 1] + $cost) < $value) {
					$value = ($previous[$j - 1] + $cost);
				}

				if ((1 < $i) && (1 < $j) && ($symbol_a === $b[$j - 2]) && ($a[$i - 2] === $b[$j - 1])) {
					if (($previous_previous[$j - 2] + 1) < $value) {
						$value = ($previous_previous[$j - 2] + 1);
					}
				}

				$current[$j] = $value;

				if ($value < $best) {
					$best = $value;
				}
			}

			if ($best > $limit) {
				return ($limit + 1);
			}

			$previous_previous = $previous;
			$previous          = $current;
		}

		return $previous[$length_b];
	}




	//
	// _register
	//
	// Admit an identifier to the registry and return its canonical rendering.
	//
	// The only write path, and the only place an identifier is rendered: the
	// read path never builds a string, it returns the one stored here. Writing
	// is strict, so the one line below is the single definition of what an
	// identifier looks like -- fixed groups joined by the delimiter, counted off
	// in symbols rather than split on a delimiter that carries nothing and may
	// not have survived.
	//

	private function _register(string $symbols): string
	{
		$canonical = implode(self::DELIMITER, str_split($symbols, self::GROUP));

		$signature = $this->_signature($symbols);

		$this->registry[$symbols]   = $canonical;
		$this->mask[$symbols]       = $signature[0];
		$this->duplicates[$symbols] = $signature[1];

		return $canonical;
	}




	//
	// _normalize
	//
	// Uppercase the candidate and drop every character outside the alphabet.
	//
	// This is deliberately liberal: delimiter damage is the most frequent
	// damage of all, so hyphens turned into dashes, underscores, spaces or
	// nothing must cost nothing, and so must a backtick wrapper or stray
	// whitespace. A lookalike a model substituted (O for 0, I or L for 1) is
	// not in the alphabet either and is dropped rather than guessed at, which
	// leaves a short candidate that registry repair recovers as the insertion
	// it was.
	//
	// The layers below filter whatever this admits.
	//

	private function _normalize(string $candidate): string
	{
		$result = '';

		$candidate = strtoupper($candidate);
		$length    = strlen($candidate);

		for ($i = 0; $i < $length; $i++) {
			if (FALSE !== strpos(self::ALPHABET, $candidate[$i])) {
				$result .= $candidate[$i];
			}
		}

		return $result;
	}




	//
	// _check
	//
	// The two check symbols for a payload under a context.
	//
	// The code is a minimum-distance-3 Reed-Solomon-style code over the
	// alphabet. Because the alphabet size is prime the field arithmetic is
	// ordinary arithmetic modulo 29: the check symbols are chosen so that two
	// weighted sums over all 10 symbols -- one unweighted, one weighted by the
	// positions 1 to 10 -- both come out to the values the context requires.
	//
	// With x at position 9 and y at position 10 that is
	//
	//   x + y      = A   (mod 29)
	//   9x + 10y   = B   (mod 29)
	//
	// whose matrix has determinant 1, so y = B - 9A and x = A - y, and there is
	// exactly one answer to solve for rather than a space to search.
	//
	// Distance 3 detects every one- and two-symbol error unconditionally, which
	// is precisely what one damage event can do to a candidate that is still
	// canonical length: substitute a token of up to two adjacent symbols, or
	// transpose an adjacent pair. Nothing the contract covers can pass through
	// the fast accept as pristine.
	//

	private function _check(string $payload, string $context): string
	{
		$required = $this->_required($context);

		$sum      = 0;
		$weighted = 0;

		$length = strlen($payload);

		for ($i = 0; $i < $length; $i++) {
			$value = strpos(self::ALPHABET, $payload[$i]);

			$sum      += $value;
			$weighted += (($i + 1) * $value);
		}

		$a = (($required[0] - $sum) % self::BASE);
		$b = (($required[1] - $weighted) % self::BASE);

		$a = (($a + self::BASE) % self::BASE);
		$b = (($b + self::BASE) % self::BASE);

		$y = ((($b - (9 * $a)) % self::BASE) + self::BASE) % self::BASE;
		$x = ((($a - $y) % self::BASE) + self::BASE) % self::BASE;

		return (self::ALPHABET[$x] . self::ALPHABET[$y]);
	}




	//
	// _verify
	//
	// Whether a full-length candidate carries the check symbols its payload
	// requires under this context.
	//
	// Recomputing them and comparing is the same test as evaluating both sums,
	// and it is the one place both the fast accept and the re-verification
	// inside repair go through.
	//

	private function _verify(string $symbols, string $context): bool
	{
		if (self::LENGTH !== strlen($symbols)) {
			return FALSE;
		}

		$payload = substr($symbols, 0, self::PAYLOAD);

		return (substr($symbols, self::PAYLOAD) === $this->_check($payload, $context));
	}




	//
	// _required
	//
	// The two values the check sums must come out to, derived from the context.
	//
	// Binding is native to the code: the arithmetic and its guarantees do not
	// change, only the targets do, and an identifier resolved under a context
	// it was not minted under fails. The empty context is a context like any
	// other and takes the fixed pair.
	//
	// The derivation has to be deterministic over a canonical encoding of the
	// context and identical at mint and at resolve, so two components that
	// spell the same context differently do not end up with two contexts.
	// Trimmed, internal whitespace collapsed, lowercased.
	//

	private function _required(string $context): array
	{
		$canonical = strtolower(trim($context));

		if ('' === $canonical) {
			return array(0, 0);
		}

		$collapsed = preg_replace('/\s+/', ' ', $canonical);

		if (NULL !== $collapsed) {
			$canonical = $collapsed;
		}

		$digest = hash('sha256', $canonical, TRUE);
		$words  = unpack('N2', substr($digest, 0, 8));

		return array(($words[1] % self::BASE), ($words[2] % self::BASE));
	}




	//
	// _signature
	//
	// The screening pair for an identifier: a mask with one bit set per
	// alphabet symbol it contains, and how many of its slots are repeats of a
	// symbol already counted.
	//

	private function _signature(string $symbols): array
	{
		$mask   = 0;
		$length = strlen($symbols);

		for ($i = 0; $i < $length; $i++) {
			$mask |= (1 << strpos(self::ALPHABET, $symbols[$i]));
		}

		$distinct = 0;

		for ($bit = 0; $bit < self::BASE; $bit++) {
			if (0 !== ($mask & (1 << $bit))) {
				$distinct++;
			}
		}

		return array($mask, ($length - $distinct));
	}




	//
	// _build_popcount
	//
	// Population counts for every 16-bit half word, so that the count of bits
	// in a 29-bit mask is two lookups. Built on the first scan and never
	// rebuilt; an object that only ever mints one identifier into an empty
	// registry never builds it at all.
	//

	private function _build_popcount(): void
	{
		$table = array_fill(0, 65536, 0);

		for ($i = 1; $i < 65536; $i++) {
			$table[$i] = ($table[($i >> 1)] + ($i & 1));
		}

		$this->popcount = $table;
	}




	//
	// _load_vectors
	//
	// Read one vector file and hand back the array of cases it carries.
	//
	// The header is checked before the cases are. A file that declares another
	// scheme version, or that has been copied over the wrong name, would
	// otherwise be graded case by case and fail somewhere deep on a mismatch
	// that says nothing about what actually went wrong.
	//

	private function _load_vectors(string $filename, string $key): array|false
	{
		$path = (__DIR__ . '/vectors/' . $filename);

		if (FALSE === is_file($path)) {
			$this->last_error = 'Vector file is unreadable: vectors/' . $filename;

			return FALSE;
		}

		$json = file_get_contents($path);

		if (FALSE === $json) {
			$this->last_error = 'Vector file is unreadable: vectors/' . $filename;

			return FALSE;
		}

		$document = json_decode($json, TRUE);

		if (FALSE === is_array($document)) {
			$this->last_error = 'Vector file is not valid JSON: vectors/' . $filename;

			return FALSE;
		}

		if ((FALSE === isset($document['scheme'])) || (FALSE === is_int($document['scheme']))) {
			$this->last_error = 'Vector file declares no scheme version: vectors/' . $filename;

			return FALSE;
		}

		if (1 !== $document['scheme']) {
			$this->last_error = 'Vector file is for another scheme version: vectors/' . $filename . ' declares ' . $document['scheme'];

			return FALSE;
		}

		if ((FALSE === isset($document['file'])) || ($filename !== $document['file'])) {
			$this->last_error = 'Vector file is not the one it claims to be: vectors/' . $filename;

			return FALSE;
		}

		if ((FALSE === isset($document[$key])) || (FALSE === is_array($document[$key]))) {
			$this->last_error = 'Vector file carries no ' . $key . ': vectors/' . $filename;

			return FALSE;
		}

		return $document[$key];
	}




	//
	// _test_check
	//
	// check.json: payload and context in, the two check symbols out.
	//
	// This is where a second implementation most often lands somewhere plausible
	// and wrong. The context digest is eight bytes read big-endian, and reading
	// them little-endian produces check symbols that look perfectly correct and
	// match nothing. These cases carry no label of their own, so the payload
	// identifies them.
	//

	private function _test_check(): bool
	{
		$cases = $this->_load_vectors('check.json', 'cases');

		if (FALSE === $cases) {
			return FALSE;
		}

		foreach ($cases as $index => $case) {
			$check = $this->_check($case['payload'], $case['context']);

			if ($case['check'] !== $check) {
				return $this->_test_failed('check.json', 'case ' . $index . ', payload ' . $case['payload'], $this->_test_show($case['check']), $this->_test_show($check));
			}
		}

		return TRUE;
	}




	//
	// _test_normalize
	//
	// normalize.json: raw input in, the undelimited symbols out.
	//
	// The last case is the one worth staring at. Uppercasing here is byte-wise,
	// so U+017F LATIN SMALL LETTER LONG S is left alone and then dropped as not
	// being in the alphabet, where a language whose upper-casing is codepoint-
	// wise turns it into an S that is in the alphabet and reads an eleventh
	// symbol.
	//

	private function _test_normalize(): bool
	{
		$cases = $this->_load_vectors('normalize.json', 'cases');

		if (FALSE === $cases) {
			return FALSE;
		}

		foreach ($cases as $case) {
			$output = $this->_normalize($case['input']);

			if ($case['output'] !== $output) {
				return $this->_test_failed('normalize.json', $case['label'], $this->_test_show($case['output']), $this->_test_show($output));
			}
		}

		return TRUE;
	}




	//
	// _test_distance
	//
	// distance.json: bounded Damerau-Levenshtein distance.
	//
	// Two things are pinned at once, and only one of them is arithmetic. An
	// adjacent transposition costs one operation, which is what levenshtein()
	// would price at two; and past the limit the walk reports limit + 1 rather
	// than the true distance, so an implementation whose arithmetic is right and
	// whose bound is not still disagrees here.
	//

	private function _test_distance(): bool
	{
		$cases = $this->_load_vectors('distance.json', 'cases');

		if (FALSE === $cases) {
			return FALSE;
		}

		foreach ($cases as $case) {
			$distance = $this->_distance($case['a'], $case['b'], $case['limit']);

			if ($case['distance'] !== $distance) {
				return $this->_test_failed('distance.json', $case['label'], (string) $case['distance'], (string) $distance);
			}
		}

		return TRUE;
	}




	//
	// _test_resolve
	//
	// resolve.json: the damage contract end to end, in four groups, each
	// carrying the registry its expectations assume.
	//
	// The wording of the failure is asserted and not merely the fact of it. The
	// specification treats a repair and a hard failure as two distinct telemetry
	// signals, and the wording is where that distinction lives. A success
	// carrying no error at all is the same assertion from the other side: it is
	// what makes a repair silent.
	//

	private function _test_resolve(): bool
	{
		$groups = $this->_load_vectors('resolve.json', 'groups');

		if (FALSE === $groups) {
			return FALSE;
		}

		foreach ($groups as $group) {
			foreach ($group['cases'] as $case) {
				//
				// A fresh object per case, exactly as the expectations were
				// recorded, so that nothing one case leaves behind can decide
				// the next one's answer.
				//
				$llmuid = new self($group['registry']);

				$result = $llmuid->resolve($case['candidate'], $case['context']);
				$error  = $llmuid->last_error();

				//
				// The vectors spell absence as JSON null, the one form every
				// language agrees on. This class spells it FALSE.
				//
				$expect       = (NULL === $case['expect']) ? FALSE : $case['expect'];
				$expect_error = (NULL === $case['error'])  ? FALSE : $case['error'];

				$label = ($group['name'] . ', ' . $case['label']);

				if ($expect !== $result) {
					return $this->_test_failed('resolve.json', $label, $this->_test_show($expect), $this->_test_show($result));
				}

				if ($expect_error !== $error) {
					return $this->_test_failed('resolve.json', $label . ', error', $this->_test_show($expect_error), $this->_test_show($error));
				}
			}
		}

		return TRUE;
	}




	//
	// _test_scan
	//
	// The registry scan at the separation radius, in the direction minting
	// depends on and nothing else exercises.
	//
	// _near() is asked for two radii and only one of them is covered. Repair
	// asks at 2, which resolve.json pins from both sides. Minting asks at 4, and
	// there the answer is always empty -- that is what an accepted draw means --
	// so a _near() that had stopped finding anything at 4 would look identical
	// from outside, mint() would accept every draw it was handed, and separation
	// would quietly stop being enforced with no symptom anywhere.
	//
	// So the screen is checked against the distance it exists to accelerate. The
	// mask screen discards an entry before any matrix is built, on the claim
	// that it can only ever discard one genuinely out of range; that claim is
	// what this pins. For candidates spread either side of the radius, _near()
	// and _distance() must agree entry by entry.
	//
	// What this cannot reach is whether mint() still asks. Forcing the check to
	// fire would mean crowding a space of 29^8 until a hundred consecutive draws
	// were all rejected, and reaching in to steer the draw would mean a seam in
	// the random source that the scheme does not allow.
	//

	private function _test_scan(): bool
	{
		//
		// Seeded through the constructor, which admits identifiers without
		// enforcing anything, so the entries are fixed and known rather than
		// drawn. The all-digit one is here because PHP stores its registry key
		// as an integer, and the scan casts it back.
		//
		$registry = array('K7-M3-XR-9D-CN', 'T5-VQ-2B-NH-2R', '9W-C4-KZ-FM-5G', '43-96-92-98-62');

		$llmuid = new self($registry);

		$entries = array();

		foreach ($registry as $identifier) {
			$entries[] = $this->_normalize($identifier);
		}

		//
		// Candidates spread from zero edits to past the radius, built from two
		// entries: an ordinary one, and the all-digit one, so that the cast the
		// scan puts around an integer registry key is on the path. The strict
		// search below is what holds that cast in place -- without it the scan
		// hands back an integer where every caller is typed for a string, and
		// production only survives it because PHP coerces.
		//
		$candidates = array();

		foreach (array($entries[0], $entries[3]) as $base) {
			$candidates[] = $base;

			$damaged = $base;

			//
			// Substitutions at spreading positions walk the boundary, from one
			// edit out to one past the radius.
			//
			for ($i = 0; $i < 5; $i++) {
				$position = ($i * 2);

				$damaged[$position] = ('B' === $damaged[$position]) ? 'C' : 'B';

				$candidates[] = $damaged;
			}

			//
			// The shifts either side of it, because a length change is what
			// makes the screen's span the longer of the two rather than the
			// canonical length.
			//
			$candidates[] = substr_replace($base, 'B', 4, 0);
			$candidates[] = substr_replace($base, 'BC', 4, 0);
			$candidates[] = substr_replace($base, '', 4, 1);
			$candidates[] = substr_replace($base, '', 4, 2);
			$candidates[] = $this->_test_transpose($base, 4);
		}

		$candidates[] = $entries[1];

		foreach ($candidates as $candidate) {
			$near = $llmuid->_near($candidate, self::SEPARATION);

			foreach ($entries as $entry) {
				$found  = (FALSE !== array_search($entry, $near, TRUE));
				$within = ($this->_distance($candidate, $entry, self::SEPARATION) <= self::SEPARATION);

				if ($found !== $within) {
					return $this->_test_failed('the separation scan', $candidate . ' against ' . $entry, ($within ? 'found' : 'not found'), ($found ? 'found' : 'not found'));
				}
			}
		}

		return TRUE;
	}




	//
	// _test_mint
	//
	// The invariants no vector can pin.
	//
	// mint() is random by design, so there is no fixed case to record and the
	// vectors leave it alone entirely. What can still be checked is what minting
	// is answerable for: that a drawn identifier renders canonically, survives
	// each of the four damage events, refuses a context it was not minted under,
	// and lands further than twice the repair radius from everything issued
	// before it.
	//
	// Deliberately deterministic even so. Drawing fresh damage on every run
	// would find cases nobody thought to write down, and would also report
	// failures that do not reproduce -- and a check a release depends on has to
	// give the same answer twice.
	//

	private function _test_mint(): bool
	{
		$batch = 20;

		$llmuid = new self();

		$issued = array();

		for ($i = 0; $i < $batch; $i++) {
			$identifier = $llmuid->mint();

			if (FALSE === $identifier) {
				$this->last_error = 'Self-test failed in mint, drawing a batch: ' . $llmuid->last_error();

				return FALSE;
			}

			$issued[] = $identifier;
		}

		foreach ($issued as $identifier) {
			$symbols = $this->_normalize($identifier);

			//
			// Writing is strict: one rendering, counted off in symbols. Rebuild
			// it from what liberal reading gave back and the two must be the
			// same string.
			//
			$canonical = implode(self::DELIMITER, str_split($symbols, self::GROUP));

			if ($canonical !== $identifier) {
				return $this->_test_failed('mint', 'canonical rendering', $this->_test_show($canonical), $this->_test_show($identifier));
			}

			//
			// The four damage events, one edit apiece, at a position inside the
			// payload. Every one must come back as the identifier it damaged,
			// and come back silently -- the whole contract, drawn fresh rather
			// than read out of a file. The replacement symbol is chosen against
			// the one it displaces, since a payload is random and a fixed one
			// would sometimes substitute a symbol for itself.
			//
			$replacement = ('B' === $symbols[4]) ? 'C' : 'B';

			$candidates = array(
				'pristine'      => $identifier,
				'substitution'  => substr_replace($symbols, $replacement, 4, 1),
				'transposition' => $this->_test_transpose($symbols, 4),
				'insertion'     => substr_replace($symbols, $replacement, 4, 0),
				'deletion'      => substr_replace($symbols, '', 4, 1),
			);

			foreach ($candidates as $damage => $candidate) {
				if (FALSE === $this->_test_round_trip($llmuid, $candidate, '', $identifier, $damage)) {
					return FALSE;
				}
			}
		}

		//
		// Separation, measured independently of the code that enforces it.
		// Nothing issued may sit within twice the repair radius of anything else
		// issued, which is what makes it impossible for one damage event to
		// carry a candidate into a neighbour's radius.
		//
		// This asserts the property, and at twenty draws out of 29^8 that is all
		// it can do: draws are this far apart on their own, so a mint() that had
		// quietly stopped enforcing separation would still pass here. The
		// machinery it would have stopped using is covered instead, by
		// _test_scan() above; what neither reaches is whether mint() still asks.
		//
		foreach ($issued as $i => $a) {
			foreach ($issued as $j => $b) {
				if ($i < $j) {
					$distance = $this->_distance($this->_normalize($a), $this->_normalize($b), self::SEPARATION);

					if ($distance <= self::SEPARATION) {
						return $this->_test_failed('mint', 'separation between ' . $a . ' and ' . $b, 'more than ' . self::SEPARATION, (string) $distance);
					}
				}
			}
		}

		//
		// The registry outlives the object: what registry() hands out is what
		// the constructor takes back, in mint order and canonical rendering,
		// with nothing added and nothing lost.
		//
		$persisted = $llmuid->registry();

		if ($issued !== $persisted) {
			return $this->_test_failed('mint', 'registry', $batch . ' identifiers in mint order', count($persisted) . ' that do not match');
		}

		$restored = new self($persisted);

		foreach ($issued as $identifier) {
			if (FALSE === $this->_test_round_trip($restored, $identifier, '', $identifier, 'registry round trip')) {
				return FALSE;
			}
		}

		return $this->_test_context();
	}




	//
	// _test_context
	//
	// Binding the check symbols to a context, on a freshly drawn identifier.
	//
	// The wrong context is searched for rather than assumed. Two contexts land
	// on the same check symbols about once in 841, which on a drawn payload
	// would fail this perhaps one run in eight hundred, so the first candidate
	// whose check symbols actually differ is the one used. 'invoice line' is in
	// the list because it is a different context from 'invoice' and not a longer
	// spelling of it.
	//

	private function _test_context(): bool
	{
		$context = 'invoice';

		$llmuid = new self();

		$identifier = $llmuid->mint($context);

		if (FALSE === $identifier) {
			$this->last_error = 'Self-test failed in mint, binding a context: ' . $llmuid->last_error();

			return FALSE;
		}

		if (FALSE === $this->_test_round_trip($llmuid, $identifier, $context, $identifier, 'right context')) {
			return FALSE;
		}

		$payload = substr($this->_normalize($identifier), 0, self::PAYLOAD);
		$check   = $this->_check($payload, $context);

		$wrong = '';

		foreach (array('receipt', 'order', 'shipment', 'invoice line') as $other) {
			if ($check !== $this->_check($payload, $other)) {
				$wrong = $other;

				break;
			}
		}

		if ('' === $wrong) {
			$this->last_error = 'Self-test failed in mint, wrong context: every candidate context collides with ' . $context;

			return FALSE;
		}

		$result = $llmuid->resolve($identifier, $wrong);

		if (FALSE !== $result) {
			return $this->_test_failed('mint', 'wrong context', 'FALSE', $this->_test_show($result));
		}

		//
		// Named as its own failure rather than folded into the generic one. A
		// genuine identifier under the wrong context is the case that repair
		// would silently swallow if the re-verification inside it were ever
		// removed, and this wording is what would notice.
		//
		$expect_error = ('Wrong context: ' . $identifier . ' was not issued under this context');

		if ($expect_error !== $llmuid->last_error()) {
			return $this->_test_failed('mint', 'wrong context, error', $this->_test_show($expect_error), $this->_test_show($llmuid->last_error()));
		}

		return TRUE;
	}




	//
	// _test_round_trip
	//
	// One resolve that has to succeed silently: the candidate comes back as the
	// identifier it stands for, and the error slot is left empty. A repair that
	// reports an error is not a repair.
	//

	private function _test_round_trip(self $llmuid, string $candidate, string $context, string $expect, string $label): bool
	{
		$result = $llmuid->resolve($candidate, $context);

		if ($expect !== $result) {
			return $this->_test_failed('mint', $label, $this->_test_show($expect), $this->_test_show($result));
		}

		$error = $llmuid->last_error();

		if (FALSE !== $error) {
			return $this->_test_failed('mint', $label . ', error', 'FALSE', $this->_test_show($error));
		}

		return TRUE;
	}




	//
	// _test_transpose
	//
	// Swap the symbol at $position with the one after it: one damage event, one
	// edit operation.
	//

	private function _test_transpose(string $symbols, int $position): string
	{
		$swapped = $symbols;

		$swapped[$position]     = $symbols[$position + 1];
		$swapped[$position + 1] = $symbols[$position];

		return $swapped;
	}




	//
	// _test_failed
	//
	// Record a mismatch and report it back up. One phrasing for all of them, so
	// that a failure always names the file, the case and both values, which is
	// the whole of what is needed to go and find it.
	//

	private function _test_failed(string $file, string $label, string $expected, string $actual): bool
	{
		$this->last_error = 'Self-test failed in ' . $file . ', ' . $label . ': expected ' . $expected . ', got ' . $actual;

		return FALSE;
	}




	//
	// _test_show
	//
	// A value as it should read inside a failure message: quoted when it is a
	// string, since the empty one is otherwise invisible, and spelled out when
	// it is the FALSE this class returns for absence.
	//

	private function _test_show(string|false $value): string
	{
		if (FALSE === $value) {
			return 'FALSE';
		}

		return ("'" . $value . "'");
	}
}
