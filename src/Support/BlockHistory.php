<?php

namespace CarlJanzell\FilamentPageBuilder\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Undo history for one canvas, held in the session rather than in component state.
 *
 * A block array is easily tens of kilobytes, and a public Livewire property round-trips
 * to the browser on every request — thirty of them would put megabytes on the wire for a
 * feature nobody uses on most edits. Keeping the stack server side costs the payload
 * nothing.
 *
 * Only one canvas is ever open at a time, so the session holds one stack, tagged with the
 * component it belongs to. Opening a different canvas discards the old one instead of
 * accumulating a stack per component id for the life of the session.
 */
class BlockHistory
{
    public const KEY = 'filament-page-builder.history';

    public const LIMIT = 30;

    public function __construct(protected Session $session, protected string $owner) {}

    public function canUndo(): bool
    {
        return $this->stack()['past'] !== [];
    }

    public function canRedo(): bool
    {
        return $this->stack()['future'] !== [];
    }

    /**
     * Record the state about to be replaced.
     *
     * Recording a new step abandons the redo branch, as every undo stack does: once you
     * change course, the future you undid out of is no longer reachable.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function push(array $blocks): void
    {
        $stack = $this->stack();

        $stack['past'][] = $blocks;
        $stack['past'] = array_slice($stack['past'], -static::LIMIT);
        $stack['future'] = [];

        $this->write($stack);
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @return array<int, array<string, mixed>>|null
     */
    public function undo(array $current): ?array
    {
        return $this->step('past', 'future', $current);
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @return array<int, array<string, mixed>>|null
     */
    public function redo(array $current): ?array
    {
        return $this->step('future', 'past', $current);
    }

    public function clear(): void
    {
        $this->session->forget(static::KEY);
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @return array<int, array<string, mixed>>|null
     */
    protected function step(string $from, string $to, array $current): ?array
    {
        $stack = $this->stack();

        if ($stack[$from] === []) {
            return null;
        }

        $blocks = array_pop($stack[$from]);
        $stack[$to][] = $current;
        $stack[$to] = array_slice($stack[$to], -static::LIMIT);

        $this->write($stack);

        return $blocks;
    }

    /**
     * @return array{owner: string, past: array<int, mixed>, future: array<int, mixed>}
     */
    protected function stack(): array
    {
        $stack = $this->session->get(static::KEY);

        if (! is_array($stack) || ($stack['owner'] ?? null) !== $this->owner) {
            return ['owner' => $this->owner, 'past' => [], 'future' => []];
        }

        return $stack;
    }

    /**
     * @param  array{owner: string, past: array<int, mixed>, future: array<int, mixed>}  $stack
     */
    protected function write(array $stack): void
    {
        $this->session->put(static::KEY, $stack);
    }
}
