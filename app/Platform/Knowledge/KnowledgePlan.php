<?php

namespace App\Platform\Knowledge;

use App\Models\KnowledgeChunk;
use Illuminate\Support\Collection;

/** Outcome of KnowledgePlanner::plan(). */
final class KnowledgePlan
{
    /**
     * @param  Collection<int, KnowledgeChunk>  $fragments
     */
    private function __construct(
        /** The selection could not be made (provider error or invalid answer). */
        public readonly bool $failed,
        public readonly Collection $fragments,
        /**
         * 'selected'; 'failed'; 'not_needed' (the message needs no source
         * content); 'no_sources' or 'no_match' (it needs them, but there is
         * nothing to choose from, or no valid section was chosen).
         */
        public readonly string $status,
    ) {}

    public static function selected(Collection $fragments): self
    {
        return new self(false, $fragments, 'selected');
    }

    public static function nothing(string $status): self
    {
        return new self(false, collect(), $status);
    }

    public static function failed(): self
    {
        return new self(true, collect(), 'failed');
    }

    /** The answer needs the sources but none could be selected. */
    public function unsupported(): bool
    {
        return in_array($this->status, ['no_sources', 'no_match'], true);
    }
}
