<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Support\Digits;
use App\Support\Money;
use App\Support\Spreadsheet\SpreadsheetReaders;
use Illuminate\Database\ConnectionInterface;

/**
 * Bulk customer import, with a dry run that is the real thing stopped before the write.
 *
 * The same guarantee `BulkPriceUpdater` makes, and for the same reason: an import that
 * reports one outcome and performs another is worse than no import, because the shop
 * only discovers the difference weeks later in their balances. `analyse()` and
 * `import()` walk identical code; the second one commits.
 *
 * ## What counts as the same person
 *
 * Mobile number first, national id second. Both are normalised to Latin digits with
 * separators stripped before comparison, because the sheet was typed by a human on a
 * Persian keyboard and `۰۹۱۲-۱۱۱-۲۲۳۳` is the same customer as `09121112233`.
 * Duplicate rows *within one sheet* are reported rather than silently merged — the
 * shop's own list having the same person twice is something they should know.
 *
 * ## Opening balances are money
 *
 * Golden rule 2. A sheet is quoted in toman roughly always, so the unit is chosen on
 * the wizard and converted here; nothing downstream sees anything but integer rial.
 */
final class PartyImporter
{
    /** Columns a sheet can be mapped onto. The key is what the mapping refers to. */
    public const FIELDS = [
        'name' => 'نام',
        'company_name' => 'نام شرکت',
        'mobile' => 'شماره همراه',
        'phone' => 'تلفن ثابت',
        'email' => 'ایمیل',
        'national_id' => 'کد ملی',
        'economic_code' => 'کد اقتصادی',
        'opening_balance' => 'مانده اولیه',
        'credit_limit' => 'سقف اعتبار',
        'notes' => 'توضیحات',
    ];

    public const OUTCOME_CREATE = 'create';

    public const OUTCOME_UPDATE = 'update';

    public const OUTCOME_DUPLICATE = 'duplicate_in_file';

    public const OUTCOME_ERROR = 'error';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SpreadsheetReaders $readers,
    ) {}

    /**
     * The header row plus a few sample rows, for the mapping screen.
     *
     * @return array{headers: list<string>, samples: list<list<string>>, total_columns: int}
     */
    public function preview(string $path, string $extension, int $samples = 5): array
    {
        $rows = [];

        foreach ($this->readers->forExtension($extension)->rows($path, $samples + 1) as $row) {
            $rows[] = $row;
        }

        $headers = array_shift($rows) ?? [];

        return [
            'headers' => $headers,
            'samples' => $rows,
            'total_columns' => count($headers),
        ];
    }

    /**
     * Walk the sheet and report what would happen, writing nothing.
     *
     * @param  array<string, int|null>  $mapping  field => column index
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function analyse(string $path, string $extension, array $mapping, string $kind, string $unit): array
    {
        return $this->walk($path, $extension, $mapping, $kind, $unit, commit: false);
    }

    /**
     * Do it. One transaction: a half-imported customer list is worse than none, because
     * nobody can tell which half.
     *
     * @param  array<string, int|null>  $mapping
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function import(string $path, string $extension, array $mapping, string $kind, string $unit): array
    {
        /** @var array{rows: list<array<string, mixed>>, counts: array<string, int>} $result */
        $result = $this->connection->transaction(
            fn (): array => $this->walk($path, $extension, $mapping, $kind, $unit, commit: true)
        );

        return $result;
    }

    /**
     * @param  array<string, int|null>  $mapping
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    private function walk(string $path, string $extension, array $mapping, string $kind, string $unit, bool $commit): array
    {
        $reader = $this->readers->forExtension($extension);

        $rows = [];
        $counts = [
            self::OUTCOME_CREATE => 0,
            self::OUTCOME_UPDATE => 0,
            self::OUTCOME_DUPLICATE => 0,
            self::OUTCOME_ERROR => 0,
        ];

        // Keyed by the number itself. PHP coerces an all-digit string key to an int,
        // which is harmless here but is why these are `array-key` and not `string`.
        $seenMobiles = [];
        $seenNationalIds = [];

        $lineNumber = 0;

        foreach ($reader->rows($path) as $raw) {
            $lineNumber++;

            // The header row is data to the reader and noise to us.
            if ($lineNumber === 1) {
                continue;
            }

            if ($this->isBlank($raw)) {
                continue;
            }

            $fields = $this->extract($raw, $mapping, $unit);
            $row = ['line' => $lineNumber, 'name' => $fields['name'], 'mobile' => $fields['mobile']];

            $error = $this->validate($fields);

            if ($error !== null) {
                $rows[] = $row + ['outcome' => self::OUTCOME_ERROR, 'message' => $error];
                $counts[self::OUTCOME_ERROR]++;

                continue;
            }

            $duplicateOf = $this->duplicateWithinFile($fields, $seenMobiles, $seenNationalIds);

            if ($duplicateOf !== null) {
                $rows[] = $row + [
                    'outcome' => self::OUTCOME_DUPLICATE,
                    'message' => "همین شخص در سطر {$duplicateOf} همین فایل هم هست.",
                ];
                $counts[self::OUTCOME_DUPLICATE]++;

                continue;
            }

            if ($fields['mobile'] !== null) {
                $seenMobiles[$fields['mobile']] = $lineNumber;
            }

            if ($fields['national_id'] !== null) {
                $seenNationalIds[$fields['national_id']] = $lineNumber;
            }

            $existing = $this->findExisting($fields);

            if ($existing instanceof Party) {
                $rows[] = $row + [
                    'outcome' => self::OUTCOME_UPDATE,
                    'message' => "با «{$existing->name}» تطبیق داده شد.",
                ];
                $counts[self::OUTCOME_UPDATE]++;

                if ($commit) {
                    $this->applyTo($existing, $fields);
                }

                continue;
            }

            $rows[] = $row + ['outcome' => self::OUTCOME_CREATE, 'message' => null];
            $counts[self::OUTCOME_CREATE]++;

            if ($commit) {
                $this->create($fields, $kind);
            }
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * @param  list<string>  $raw
     * @param  array<string, int|null>  $mapping
     * @return array<string, string|int|null>
     */
    private function extract(array $raw, array $mapping, string $unit): array
    {
        $value = static function (?int $index) use ($raw): ?string {
            if ($index === null) {
                return null;
            }

            $cell = trim($raw[$index] ?? '');

            return $cell === '' ? null : $cell;
        };

        $money = function (?string $input) use ($unit): ?int {
            if ($input === null) {
                return null;
            }

            $digits = Digits::toLatin($input);
            $normalised = preg_replace('/[^\d-]/', '', $digits) ?? '';

            if ($normalised === '' || $normalised === '-') {
                return null;
            }

            $amount = (int) $normalised;

            return $unit === Money::UNIT_TOMAN ? Money::fromToman($amount) : $amount;
        };

        $phone = static function (?string $input): ?string {
            if ($input === null) {
                return null;
            }

            $digits = Digits::toLatin($input);

            return preg_replace('/[^\d+]/', '', $digits) ?: null;
        };

        return [
            'name' => $value($mapping['name'] ?? null),
            'company_name' => $value($mapping['company_name'] ?? null),
            'mobile' => $phone($value($mapping['mobile'] ?? null)),
            'phone' => $phone($value($mapping['phone'] ?? null)),
            'email' => $value($mapping['email'] ?? null),
            'national_id' => $phone($value($mapping['national_id'] ?? null)),
            'economic_code' => $value($mapping['economic_code'] ?? null),
            'opening_balance' => $money($value($mapping['opening_balance'] ?? null)),
            'credit_limit' => $money($value($mapping['credit_limit'] ?? null)),
            'notes' => $value($mapping['notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, string|int|null>  $fields
     */
    private function validate(array $fields): ?string
    {
        if (! is_string($fields['name']) || $fields['name'] === '') {
            return 'نام خالی است.';
        }

        $nationalId = $fields['national_id'];

        if (is_string($nationalId) && strlen($nationalId) !== 10) {
            return 'کد ملی باید ۱۰ رقم باشد.';
        }

        $mobile = $fields['mobile'];

        // Not a strict Iranian-mobile pattern on purpose: a shop's list legitimately
        // holds landlines and foreign numbers in the mobile column, and rejecting the
        // row would lose the customer over a formatting opinion.
        if (is_string($mobile) && strlen($mobile) < 7) {
            return 'شماره تماس کوتاه‌تر از حد معمول است.';
        }

        return null;
    }

    /**
     * @param  array<string, string|int|null>  $fields
     * @param  array<array-key, int>  $seenMobiles
     * @param  array<array-key, int>  $seenNationalIds
     */
    private function duplicateWithinFile(array $fields, array $seenMobiles, array $seenNationalIds): ?int
    {
        $mobile = $fields['mobile'];

        if (is_string($mobile) && isset($seenMobiles[$mobile])) {
            return $seenMobiles[$mobile];
        }

        $nationalId = $fields['national_id'];

        if (is_string($nationalId) && isset($seenNationalIds[$nationalId])) {
            return $seenNationalIds[$nationalId];
        }

        return null;
    }

    /**
     * @param  array<string, string|int|null>  $fields
     */
    private function findExisting(array $fields): ?Party
    {
        $nationalId = $fields['national_id'];

        if (is_string($nationalId)) {
            $byNationalId = Party::query()->where('national_id', $nationalId)->first();

            if ($byNationalId instanceof Party) {
                return $byNationalId;
            }
        }

        $mobile = $fields['mobile'];

        if (is_string($mobile)) {
            $contact = PartyContact::query()
                ->where('type', PartyContact::TYPE_MOBILE)
                ->where('value', $mobile)
                ->first();

            if ($contact instanceof PartyContact) {
                return Party::query()->find($contact->party_id);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|int|null>  $fields
     */
    private function create(array $fields, string $kind): Party
    {
        /** @var Party $party */
        $party = Party::query()->create([
            'kind' => $kind,
            'name' => $fields['name'],
            'company_name' => $fields['company_name'],
            'national_id' => $fields['national_id'],
            'economic_code' => $fields['economic_code'],
            'opening_balance' => $fields['opening_balance'] ?? 0,
            'credit_limit' => $fields['credit_limit'],
            'notes' => $fields['notes'],
            'is_active' => true,
        ]);

        $this->syncContacts($party, $fields);

        return $party;
    }

    /**
     * Fill gaps on an existing party; never overwrite what the shop already has.
     *
     * The sheet is an import, not a source of truth. A customer whose name was
     * corrected in the app last week must not have that correction undone by a stale
     * export — so only empty columns are filled, and the opening balance is left alone
     * entirely because it is the one figure a later ledger entry has already built on.
     *
     * @param  array<string, string|int|null>  $fields
     */
    private function applyTo(Party $party, array $fields): void
    {
        $updates = [];

        foreach (['company_name', 'national_id', 'economic_code', 'notes'] as $column) {
            if ($party->getAttribute($column) === null && $fields[$column] !== null) {
                $updates[$column] = $fields[$column];
            }
        }

        if ($party->credit_limit === null && $fields['credit_limit'] !== null) {
            $updates['credit_limit'] = $fields['credit_limit'];
        }

        if ($updates !== []) {
            $party->update($updates);
        }

        $this->syncContacts($party, $fields);
    }

    /**
     * @param  array<string, string|int|null>  $fields
     */
    private function syncContacts(Party $party, array $fields): void
    {
        foreach ([PartyContact::TYPE_MOBILE => 'mobile', PartyContact::TYPE_PHONE => 'phone', PartyContact::TYPE_EMAIL => 'email'] as $type => $field) {
            $value = $fields[$field] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $exists = PartyContact::query()
                ->where('party_id', $party->getKey())
                ->where('type', $type)
                ->where('value', $value)
                ->exists();

            if ($exists) {
                continue;
            }

            $hasPrimary = PartyContact::query()
                ->where('party_id', $party->getKey())
                ->where('type', $type)
                ->where('is_primary', true)
                ->exists();

            $party->contacts()->create([
                'type' => $type,
                'value' => $value,
                // A partial unique index allows exactly one primary per (party, type),
                // so a second number arrives as a secondary rather than a constraint
                // violation halfway through a 500-row file.
                'is_primary' => ! $hasPrimary,
            ]);
        }
    }

    /**
     * @param  list<string>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Guess a mapping from the header row.
     *
     * Saves the operator the whole wizard in the common case, and gets it wrong
     * harmlessly: every guess is shown on the mapping screen before anything is read.
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function guessMapping(array $headers): array
    {
        $hints = [
            'name' => ['نام', 'نام و نام خانوادگی', 'مشتری', 'طرف حساب', 'name', 'customer'],
            'company_name' => ['شرکت', 'نام شرکت', 'company'],
            'mobile' => ['موبایل', 'همراه', 'شماره همراه', 'تلفن همراه', 'mobile', 'cell'],
            'phone' => ['تلفن', 'تلفن ثابت', 'phone', 'tel'],
            'email' => ['ایمیل', 'رایانامه', 'email', 'mail'],
            'national_id' => ['کد ملی', 'کدملی', 'شناسه ملی', 'national'],
            'economic_code' => ['کد اقتصادی', 'اقتصادی', 'economic'],
            'opening_balance' => ['مانده', 'بدهی', 'مانده اولیه', 'balance', 'debt'],
            'credit_limit' => ['سقف', 'اعتبار', 'سقف اعتبار', 'credit'],
            'notes' => ['توضیح', 'توضیحات', 'یادداشت', 'note', 'description'],
        ];

        $mapping = [];

        foreach ($hints as $field => $needles) {
            $mapping[$field] = null;

            foreach ($headers as $index => $header) {
                $normalised = mb_strtolower(trim($header));

                foreach ($needles as $needle) {
                    if ($normalised !== '' && str_contains($normalised, mb_strtolower($needle))) {
                        $mapping[$field] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }
}
