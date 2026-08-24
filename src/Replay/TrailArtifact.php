<?php

declare(strict_types=1);

namespace Vusys\Runabout\Replay;

use Vusys\Runabout\Exceptions\InvalidJourneyException;

/**
 * The RUNABOUT_TRAIL wire format, in one place: how a trail is written out and
 * how one is read back in.
 *
 * A trail artifact is a seed plus an ordered list of execution tokens —
 * [label, step, run] triples, with an optional fourth element carrying the
 * forced draw values that value shrinking pinned. The order travels with the
 * artifact, so unlike a bare seed it reproduces repeat-heavy, partial, and
 * shrunk trails exactly.
 *
 * Encoding and decoding live together deliberately: they are two halves of one
 * compatibility contract. A token without forced draws stays a plain triple, so
 * every artifact written by an earlier version still parses.
 */
final readonly class TrailArtifact
{
    /**
     * @param  list<TrailToken>  $tokens
     * @param  array<int, array<int, int>>  $forcedDraws  Forced draw values by token index; absent ⇒ draw from the stream.
     */
    public function __construct(
        public int $seed,
        public array $tokens,
        public array $forcedDraws,
    ) {}

    /**
     * The compact replay artifact, ready to JSON-encode for RUNABOUT_TRAIL.
     *
     * @param  list<TrailToken>  $tokens
     * @param  array<int, list<int>>  $pinnedDraws  Forced values by token index; only pinned tokens appear.
     * @return array{seed: int, steps: list<array{0: string|null, 1: string, 2: int, 3?: list<int>}>}
     */
    public static function encode(int $seed, array $tokens, array $pinnedDraws): array
    {
        $steps = [];

        foreach ($tokens as $index => $token) {
            $steps[] = isset($pinnedDraws[$index])
                ? [$token->label, $token->step, $token->run, $pinnedDraws[$index]]
                : [$token->label, $token->step, $token->run];
        }

        return ['seed' => $seed, 'steps' => $steps];
    }

    /**
     * Read a RUNABOUT_TRAIL value: JSON, or "@path" to read the JSON from disk.
     *
     * @return array<array-key, mixed>
     */
    public static function decode(string $raw): array
    {
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            $contents = @file_get_contents($path);

            if ($contents === false) {
                throw new InvalidJourneyException(sprintf('Could not read the trail artifact file "%s".', $path));
            }

            $raw = $contents;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new InvalidJourneyException('A trail artifact must be JSON like {"seed":123,"steps":[[null,"step name",1]]}.');
        }

        return $decoded;
    }

    /**
     * Validate a decoded artifact into a seed and typed tokens.
     *
     * @param  array<array-key, mixed>  $artifact
     */
    public static function parse(array $artifact): self
    {
        $seed = $artifact['seed'] ?? null;
        $steps = $artifact['steps'] ?? null;

        if (! is_int($seed) || ! is_array($steps)) {
            throw new InvalidJourneyException('A trail artifact needs an integer "seed" and a "steps" list.');
        }

        $tokens = [];
        $forcedDraws = [];
        $index = 0;

        foreach ($steps as $step) {
            if (! is_array($step) || ! array_key_exists(0, $step) || ! array_key_exists(1, $step) || ! array_key_exists(2, $step)) {
                throw new InvalidJourneyException('Each trail step must be a [label, step, run] triple.');
            }

            $label = $step[0];
            $name = $step[1];
            $run = $step[2];

            if (($label !== null && ! is_string($label)) || ! is_string($name) || ! is_int($run)) {
                throw new InvalidJourneyException('Each trail step must be [label|null, step string, run int].');
            }

            $tokens[] = new TrailToken($label, $name, $run);

            // Optional fourth element: forced draw values pinned by value shrinking.
            if (array_key_exists(3, $step)) {
                if (! is_array($step[3])) {
                    throw new InvalidJourneyException('A trail step\'s forced draws (element 4) must be a list of integers.');
                }

                $values = [];
                foreach ($step[3] as $value) {
                    if (! is_int($value)) {
                        throw new InvalidJourneyException('A trail step\'s forced draws (element 4) must be a list of integers.');
                    }
                    $values[] = $value;
                }

                $forcedDraws[$index] = $values;
            }

            $index++;
        }

        return new self($seed, $tokens, $forcedDraws);
    }
}
