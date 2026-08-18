<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Repairing Persian text that has been through a lossy code page or an Arabic keyboard.
 *
 * The companion to {@see Digits}, which does the same job for numerals. Both exist
 * because text arriving from outside this system — a spreadsheet, a paste, an Arabic
 * locale device — is *nearly* Persian, and the difference is invisible on screen and
 * fatal to a search.
 *
 * ## Why this is repair, not tidying
 *
 * **windows-1256 cannot encode Persian yeh at all.** The code page has Arabic yeh
 * (ي, U+064A) and no U+06CC. It *does* have Persian kaf (ک), Persian gaf, peh, cheh and
 * zheh. So a catalogue exported from older Iranian software physically cannot contain
 * «گوشی» — it contains «گوشي», every single time, and there was never a moment when the
 * file was right.
 *
 * Skip this step and every product name imported from legacy software fails to match
 * what the shop later types into the search box, because the shopkeeper's keyboard
 * produces U+06CC and the file holds U+064A. Nothing looks wrong. The search simply
 * returns nothing, and the phone that is definitely in stock is definitely not findable.
 *
 * ## Why it is safe to apply unconditionally
 *
 * In Persian text an Arabic yeh or kaf is always an error — the letters are visually
 * identical in every font this product ships, so no shop is deliberately distinguishing
 * them. Normalising on the way in is therefore lossless in practice and is what makes
 * one spelling exist in the database.
 */
final class PersianText
{
    /** Arabic forms that must become their Persian equivalents. */
    private const ARABIC = ['ي', 'ك', 'ى', 'ﻯ', 'ﻙ', 'ﮎ', 'ﮏ', 'ﮐ', 'ﮑ'];

    private const PERSIAN = ['ی', 'ک', 'ی', 'ی', 'ک', 'ک', 'ک', 'ک', 'ک'];

    /** Persian yeh — the character windows-1256 has no room for. */
    public const PERSIAN_YEH = 'ی';

    /** Arabic yeh — what a windows-1256 file holds in its place. */
    public const ARABIC_YEH = 'ي';

    /** Persian kaf — present in windows-1256, which is what makes the mix diagnostic. */
    public const PERSIAN_KAF = 'ک';

    /**
     * Standardise ی and ک to their Persian forms.
     */
    public static function normalise(string $value): string
    {
        return str_replace(self::ARABIC, self::PERSIAN, $value);
    }

    /**
     * Whether normalising this text would change it.
     *
     * Used to report the repair rather than perform it silently: the import screen says
     * what it changed, and the sample rows are the evidence the operator checks.
     */
    public static function needsRepair(string $value): bool
    {
        return self::normalise($value) !== $value;
    }

    /**
     * The windows-1256 fingerprint: Arabic yeh beside Persian kaf.
     *
     * A human typing on either keyboard produces a consistent pair — an Iranian keyboard
     * gives ی and ک, an Arabic one gives ي and ك. **Only a trip through windows-1256
     * produces the mixture**, because the code page carries Persian kaf and drops Persian
     * yeh onto its Arabic counterpart.
     *
     * That makes it a positive signal about a file's history rather than a guess, which
     * matters because the alternative is asking a shopkeeper which code page their export
     * used — a question nobody outside this file can answer.
     */
    public static function looksLikeLegacyCodePage(string $value): bool
    {
        return str_contains($value, self::ARABIC_YEH) && str_contains($value, self::PERSIAN_KAF);
    }
}
