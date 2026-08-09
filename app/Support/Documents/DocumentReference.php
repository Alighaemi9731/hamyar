<?php

declare(strict_types=1);

namespace App\Support\Documents;

/**
 * A record as another module's screen needs to show it: a Persian label and, where a
 * screen for it exists, somewhere to go.
 *
 * `url` is nullable on purpose. A purchase invoice is a real document long before
 * Purchasing has a page for it, and naming it without linking it is still far more
 * useful than a bare status change.
 */
final readonly class DocumentReference
{
    public function __construct(
        public string $label,
        public ?string $url = null,
    ) {}

    /**
     * @return array{label: string, url: string|null}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'url' => $this->url];
    }
}
