<?php

namespace App\Platform\Commerce;

/** A commercial reply and the domain its turn belongs to (for history). */
final class CommerceReply
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $domainKey,
    ) {}
}
