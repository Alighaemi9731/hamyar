<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\ActivityLogFilterRequest;
use App\Modules\Identity\Models\Activity;
use App\Modules\Identity\Models\User;
use App\Support\Audit\AuditSubjects;
use App\Support\Audit\Redactor;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Who changed what, and when."
 *
 * ## Read-only is a property, not a habit
 *
 * There is one action here and it is a GET. That is not a description of the current
 * state of the file — `ActivityLogRoutesTest` asserts that no route anywhere in the
 * application reaches this controller with a mutating verb, so making the audit trail
 * writable through the application requires deleting a test that says why not.
 *
 * An audit trail an operator can edit is not an audit trail. Entries leave by ageing
 * out on a retention schedule, never by request.
 *
 * ## Two ways in, and the second one matters more
 *
 * The standalone screen answers «چه اتفاقی افتاد؟» — useful, and rarely the question.
 * The question a shop actually has is «کی قیمت این گوشی را عوض کرد؟», asked while
 * looking at that gوشی. So the same screen accepts a subject and record id and renders
 * itself as *that record's* history, and the record's own page carries a «تاریخچه»
 * link into it. An owner who has to find the audit log, then reconstruct which of
 * four hundred products they were looking at, will not use it twice.
 */
final class ActivityLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(
        ActivityLogFilterRequest $request,
        AuditSubjects $subjects,
        Redactor $redactor,
    ): Response {
        $this->authorize('viewAny', Activity::class);

        $filters = $request->filters();

        // `id` as the tiebreak, not decoration: `created_at` is `timestamp(0)`, so a
        // request that writes several entries stamps them all in the same second and
        // `latest()` alone returns them in whatever order the plan happens to produce.
        // An audit trail whose entries shuffle within a second cannot be read as a
        // sequence, which is the only way anybody reads one.
        $query = Activity::query()->with('causer:id,name')->latest()->orderByDesc('id');

        $this->applyFilters($query, $filters, $subjects);

        $activities = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('settings/activity', [
            'activities' => [
                'data' => collect($activities->items())
                    ->map(fn (Activity $activity): array => $this->present($activity, $subjects, $redactor))
                    ->values()
                    ->all(),
                // `linkCollection()` rather than page numbers: `<Pagination/>` renders
                // the links Laravel builds, which is what every other paginated screen
                // in the product passes it.
                'links' => $activities->linkCollection()->toArray(),
                'total' => $activities->total(),
            ],

            'filters' => $this->presentFilters($filters),

            'subjects' => $subjects->options(),

            // Only staff who have actually done something appear. A dropdown listing
            // every user who ever existed makes the reader hunt through names that
            // cannot possibly match a row.
            'actors' => $this->actors(),

            // Present only when the screen is a record's history rather than the
            // whole log — the heading changes, and without it the page reads as the
            // global log that happens to be showing three entries.
            'record' => $this->record($filters, $subjects),
        ]);
    }

    /**
     * The filters as the screen wants them back.
     *
     * The dates go out as UTC ISO strings rather than the `۱۴۰۵/۰۶/۰۲` they arrived
     * as, because `JDatePicker` takes and returns UTC and does its own conversion
     * (golden rule 5) — handing it a Jalali string would give it a date it cannot
     * parse and a picker that silently opens on today. The page converts back when it
     * builds the query, the same way every other date-ranged screen does.
     *
     * @param  array{actor: int|null, subject: string|null, record: int|null, from: string|null, to: string|null, q: string|null}  $filters
     * @return array{actor: int|null, subject: string|null, record: int|null, from: string|null, to: string|null, q: string|null}
     */
    private function presentFilters(array $filters): array
    {
        return [
            ...$filters,
            'from' => $filters['from'] !== null
                ? Jalali::startOfDay($filters['from'])->toIso8601String()
                : null,
            'to' => $filters['to'] !== null
                ? Jalali::startOfDay($filters['to'])->toIso8601String()
                : null,
        ];
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array{actor: int|null, subject: string|null, record: int|null, from: string|null, to: string|null, q: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters, AuditSubjects $subjects): void
    {
        if ($filters['actor'] !== null) {
            $query->where('causer_type', User::class)->where('causer_id', $filters['actor']);
        }

        if ($filters['subject'] !== null) {
            // An unknown key means the caller asked for a kind of record that does not
            // exist. Matching nothing is the only honest answer: ignoring the filter
            // would show them the whole log while the screen claims to be filtered,
            // which is the shape of every audit-log bug worth having.
            $class = $subjects->classFor($filters['subject']) ?? '';

            if ($filters['record'] === null) {
                $query->where('subject_type', $class);
            } else {
                // One record's history is its own entries plus those of the records
                // the owning module says belong with it — a product's variants carry
                // its price changes. See AuditSubjects::relatedTo().
                $related = $subjects->relatedTo($filters['subject'], $filters['record']);

                $query->where(function (Builder $scoped) use ($class, $filters, $related): void {
                    $scoped->where(fn (Builder $own): Builder => $own
                        ->where('subject_type', $class)
                        ->where('subject_id', $filters['record']));

                    foreach ($related as $relatedClass => $ids) {
                        $scoped->orWhere(fn (Builder $other): Builder => $other
                            ->where('subject_type', $relatedClass)
                            ->whereIn('subject_id', $ids));
                    }
                });
            }
        }

        // Jalali range in, UTC bounds out — a report using the wrong bound loses or
        // duplicates the last evening's entries (App\Support\Jalali).
        if ($filters['from'] !== null) {
            $query->where('created_at', '>=', Jalali::startOfDay($filters['from']));
        }

        if ($filters['to'] !== null) {
            $query->where('created_at', '<=', Jalali::endOfDay($filters['to']));
        }

        if ($filters['q'] !== null) {
            // `description` only. The properties column holds before/after values that
            // have already been redacted for display but not for matching, so
            // searching it would let a reader confirm a masked secret by guessing it.
            $query->where('description', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Activity $activity, AuditSubjects $subjects, Redactor $redactor): array
    {
        /** @var class-string|null $subjectType */
        $subjectType = $activity->subject_type;

        // `attribute_changes` is where spatie v5 puts the before/after of an audited
        // model; `properties` is where a hand-written `->withProperties([...])` lands.
        // Both are read because both are used — the price-change entry that answers
        // «کی این قیمت را عوض کرد؟» is the second kind, and every `Auditable` model
        // writes the first. Reading only `properties`, as this screen did until 11c,
        // renders an empty change list for every model in the product.
        /** @var Collection<string, mixed>|null $changes */
        $changes = $activity->getAttribute('attribute_changes');

        /** @var array<string, mixed> $payload */
        $payload = $changes?->toArray() ?: [];

        if ($payload === []) {
            /** @var array<string, mixed> $payload */
            $payload = $activity->properties?->toArray() ?? [];
        }

        // Redacted on write since 11c, and again here. The second pass is for rows
        // written before that guard existed: masking only new entries would leave the
        // viewer printing in clear exactly the secrets it was built to keep printing
        // in clear, and no migration can un-write them.
        $payload = $redactor->redact($payload, $subjectType);

        /** @var array<string, mixed> $attributes */
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

        /** @var array<string, mixed> $old */
        $old = is_array($payload['old'] ?? null) ? $payload['old'] : [];

        return [
            'id' => $activity->getKey(),
            'description' => $activity->description,
            'event' => $activity->getAttribute('event'),
            'subject' => $subjects->keyFor($subjectType),
            'subject_label' => $subjects->labelFor($subjectType),
            'subject_id' => $activity->subject_id,
            'causer' => $activity->causer?->getAttribute('name'),
            'created_at' => $activity->created_at?->toIso8601String(),
            // v5 exposes the before/after payload as `properties`; there is no
            // changes() helper. `attributes` is the new state, `old` the previous.
            'changes' => $this->diff($attributes, $old),
        ];
    }

    /**
     * Pair the new value of each changed attribute with its old one.
     *
     * Done here rather than in the page because the two halves are separate objects
     * in the stored payload and a key present in one may be absent from the other —
     * a `created` event has no `old` at all. Zipping them server-side means the
     * screen renders a list of rows instead of reconciling two dictionaries.
     *
     * Fields that were empty and stayed empty are dropped. `created` logs the whole
     * watched set, so a party with no company name, no national id, no economic code
     * and no birthday produced four rows reading «— ← —» — seven of the ten lines on
     * that entry said nothing, and the three that mattered were lost among them. An
     * audit entry is read by someone looking for one specific change.
     *
     * `false` and `0` are kept: a threshold of zero and a deactivated product are
     * facts, and only null and the empty string mean "not set".
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function diff(array $attributes, array $old): array
    {
        $fields = array_values(array_unique([...array_keys($attributes), ...array_keys($old)]));

        $changes = array_map(
            fn (string $field): array => [
                'field' => $field,
                'from' => $old[$field] ?? null,
                'to' => $attributes[$field] ?? null,
            ],
            $fields,
        );

        return array_values(array_filter(
            $changes,
            static fn (array $change): bool => ! self::isBlank($change['from']) || ! self::isBlank($change['to']),
        ));
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Staff who appear as the causer of at least one entry in this shop's log.
     *
     * @return list<array{id: int, name: string}>
     */
    private function actors(): array
    {
        /** @var list<int> $ids */
        $ids = Activity::query()
            ->where('causer_type', User::class)
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id')
            ->all();

        /** @var list<array{id: int, name: string}> $actors */
        $actors = User::query()
            ->whereKey($ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();

        return $actors;
    }

    /**
     * Name the record this history belongs to, when there is one.
     *
     * The name comes from the owning module's namer, so this controller never learns
     * what a product or a party is (ADR 0003). A record that has since been deleted
     * still gets a heading — the id is a poor name, but a blank heading is a broken
     * page, and a deleted product is exactly when its history is worth reading.
     *
     * @param  array{actor: int|null, subject: string|null, record: int|null, from: string|null, to: string|null, q: string|null}  $filters
     * @return array{label: string, name: string}|null
     */
    private function record(array $filters, AuditSubjects $subjects): ?array
    {
        if ($filters['subject'] === null || $filters['record'] === null) {
            return null;
        }

        $class = $subjects->classFor($filters['subject']);

        if ($class === null) {
            return null;
        }

        return [
            'label' => $subjects->labelFor($class) ?? $filters['subject'],
            'name' => $subjects->nameFor($filters['subject'], $filters['record'])
                ?? '#'.$filters['record'],
        ];
    }
}
