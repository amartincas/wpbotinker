<?php

namespace App\Platform\Contracts;

/**
 * Optional for a domain: how it relates the customer's data and message to
 * the section titles of its knowledge sources, so the Core can pick the
 * right sections when the message alone finds none (KnowledgePlanner).
 * The guide is configuration text, never code.
 */
interface ProvidesRetrievalGuide
{
    /** Empty when the domain has no guide. */
    public function retrievalGuide(): string;
}
