<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The audit log's filters, validated.
 *
 * A read-only screen still validates its input. The filters go into a query, a Jalali
 * parser and a page heading, and the ways they break are quiet ones: a malformed date
 * throws inside `Jalali::startOfDay()` and 500s the page an owner opened because
 * something had already gone wrong, and a `record` without a `subject` would silently
 * filter on nothing.
 *
 * Every rule here is `nullable` — this is a filter bar, and an absent filter is the
 * ordinary case, not an omission.
 */
final class ActivityLogFilterRequest extends FormRequest
{
    /**
     * Validation failures land on the unfiltered log, never `back()`.
     *
     * `back()` is the default and it can loop here. The only way to reach this screen
     * with a malformed filter is a hand-edited URL, and if the reader is already
     * standing on that URL then `back()` resolves to it — the request 302s to itself,
     * fails the same rule and 302s again. Sending them to the clean screen ends the
     * cycle on a page that works and shows the message.
     *
     * @var string
     */
    protected $redirect = '/settings/activity';

    public function authorize(): bool
    {
        // The policy check stays in the controller with the rest of the module's
        // authorisation; returning true here only means "this request is well formed".
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'actor' => ['nullable', 'integer', 'min:1'],
            // Required by `record`, not the other way round: filtering to a kind of
            // record is an ordinary thing to do on its own, while a record id without
            // one is meaningless — `subject_id` is only unique within a type, so a
            // bare id would match a product, a party and a user at once. Rejected
            // rather than ignored.
            'subject' => ['nullable', 'string', 'max:64', 'required_with:record'],
            'record' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'string', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'to' => ['nullable', 'string', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'q' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Persian digits arrive from a Persian date picker and a Persian keyboard.
     *
     * Normalised before validation, not after: `۱۴۰۵/۰۶/۰۲` fails the date regex as
     * surely as `hello` does, and the resulting error would blame the shopkeeper for
     * typing their own numerals.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'from' => $this->filled('from') ? Digits::toLatin($this->string('from')->value()) : null,
            'to' => $this->filled('to') ? Digits::toLatin($this->string('to')->value()) : null,
            'actor' => $this->filled('actor') ? Digits::toLatin($this->string('actor')->value()) : null,
            'record' => $this->filled('record') ? Digits::toLatin($this->string('record')->value()) : null,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * The validated filters, in the one shape the controller and the page both use.
     *
     * `record` is dropped when `subject` is absent rather than carried as an orphan,
     * so the page's "am I a record's history?" test is a single null check.
     *
     * @return array{actor: int|null, subject: string|null, record: int|null, from: string|null, to: string|null, q: string|null}
     */
    public function filters(): array
    {
        $subject = $this->filled('subject') ? $this->string('subject')->value() : null;
        $search = $this->filled('q') ? trim($this->string('q')->value()) : null;

        return [
            'actor' => $this->filled('actor') ? $this->integer('actor') : null,
            'subject' => $subject,
            'record' => $subject !== null && $this->filled('record') ? $this->integer('record') : null,
            'from' => $this->filled('from') ? $this->string('from')->value() : null,
            'to' => $this->filled('to') ? $this->string('to')->value() : null,
            'q' => $search === '' ? null : $search,
        ];
    }
}
