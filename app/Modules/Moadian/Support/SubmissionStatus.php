<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Support;

/**
 * The authority's current view of a document already submitted.
 *
 * Separate from {@see SubmissionResult} because polling answers a different question than
 * sending: a document accepted at submission can still be rejected later, and a shop needs
 * to hear about that. Collapsing the two would make "accepted" mean two things a day apart.
 */
final readonly class SubmissionStatus
{
    public function __construct(
        /** `pending` · `accepted` · `rejected` */
        public string $state,
        public ?string $taxId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
