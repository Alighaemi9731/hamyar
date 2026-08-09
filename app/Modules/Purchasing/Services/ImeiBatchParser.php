<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Imei;

/**
 * Turns a pasted or scanned blob of IMEIs into a per-line verdict.
 *
 * The headline capability of this module: a shop receives twenty phones and should be
 * able to paste twenty IMEIs and be done. That only works if the parser is forgiving
 * about *format* and merciless about *validity*.
 *
 * Forgiving: one per line, or separated by spaces, commas, tabs or semicolons — because
 * a scanner emits newlines, a supplier's WhatsApp message uses commas, and a
 * copy-pasted spreadsheet column uses tabs. Persian and Arabic digits normalise to
 * Latin, since the numbers often arrive in a Persian document.
 *
 * Merciless: every line is Luhn-checked and looked up. A mistyped IMEI accepted at
 * intake goes unnoticed until the phone is sold or warranty-claimed, by which point the
 * paperwork trail is already broken and the passport has a hole in it.
 */
final class ImeiBatchParser
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_DUPLICATE_IN_BATCH = 'duplicate_in_batch';

    public const STATUS_ALREADY_EXISTS = 'exists';

    /**
     * @return array{
     *     lines: list<array{input: string, imei: string|null, status: string, unit_id: int|null}>,
     *     accepted: list<string>,
     *     counts: array{accepted: int, invalid: int, duplicate_in_batch: int, exists: int}
     * }
     */
    public function parse(string $blob): array
    {
        $lines = [];
        $accepted = [];
        $seen = [];
        $counts = ['accepted' => 0, 'invalid' => 0, 'duplicate_in_batch' => 0, 'exists' => 0];

        foreach ($this->tokenise($blob) as $token) {
            $normalised = Imei::normalise($token);

            if (! Imei::isValid($normalised)) {
                $lines[] = ['input' => $token, 'imei' => null, 'status' => self::STATUS_INVALID, 'unit_id' => null];
                $counts['invalid']++;

                continue;
            }

            // The same number twice in one paste is a real mistake — a scanner
            // double-triggering, or a row copied twice — and reporting it per line is
            // more useful than silently de-duplicating.
            if (isset($seen[$normalised])) {
                $lines[] = ['input' => $token, 'imei' => $normalised, 'status' => self::STATUS_DUPLICATE_IN_BATCH, 'unit_id' => null];
                $counts['duplicate_in_batch']++;

                continue;
            }

            $existing = ProductUnit::query()->matchingCode($normalised)->first();

            if ($existing instanceof ProductUnit) {
                /** @var int $unitId */
                $unitId = $existing->getKey();

                // Reported with the id so the screen can link to the device rather than
                // just saying "already exists" and leaving the operator to search.
                $lines[] = ['input' => $token, 'imei' => $normalised, 'status' => self::STATUS_ALREADY_EXISTS, 'unit_id' => $unitId];
                $counts['exists']++;

                continue;
            }

            $seen[$normalised] = true;
            $accepted[] = $normalised;
            $lines[] = ['input' => $token, 'imei' => $normalised, 'status' => self::STATUS_ACCEPTED, 'unit_id' => null];
            $counts['accepted']++;
        }

        return ['lines' => $lines, 'accepted' => $accepted, 'counts' => $counts];
    }

    /**
     * Whether a batch can be committed as-is.
     *
     * Nothing is written until the whole batch is clean or the operator explicitly skips
     * the bad rows. Half-received shipments are how stock stops reconciling, and the
     * discrepancy surfaces weeks later with no way to tell which phone was missed.
     *
     * @param  array{counts: array{accepted: int, invalid: int, duplicate_in_batch: int, exists: int}}  $result
     */
    public function isClean(array $result): bool
    {
        return $result['counts']['invalid'] === 0
            && $result['counts']['duplicate_in_batch'] === 0
            && $result['counts']['exists'] === 0
            && $result['counts']['accepted'] > 0;
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $blob): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($blob)) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== ''
        ));
    }
}
