<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The set of things the audit log will talk about, and what to call them in Persian.
 *
 * Two jobs, both of which exist because `activity_log.subject_type` stores a fully
 * qualified class name:
 *
 * 1. **A short key for URLs.** `?subject=product` rather than
 *    `?subject_type=App%5CModules%5CCatalog%5CModels%5CProduct`. A filter link that a
 *    shopkeeper can read, and one that survives a class being moved between
 *    namespaces — the stored FQCN would not, but this map is the single place that
 *    would need updating.
 * 2. **A Persian label**, and a way to name one particular record. `class_basename()`
 *    gives «ProductVariant», which is not a word in the language this product is
 *    written in, and «#4417» is not a phone.
 *
 * ## Why modules register themselves
 *
 * The obvious implementation is a `const MAP` here listing every model. That makes
 * `App\Support` depend on Catalog, CRM, Repairs and Identity — the dependency arrow
 * pointing exactly the wrong way, and a violation of golden rule 6 in the one file
 * that would be hardest to notice it in.
 *
 * So the registry knows no modules. Each module's service provider declares its own
 * subjects, which also means the audit-log filter list can never disagree with what
 * the module actually audits: adding a subject is one line beside the module that
 * owns it.
 */
final class AuditSubjects
{
    /**
     * Keyed by the short URL key.
     *
     * @var array<string, array{class: class-string<Model>, label: string, position: int, namer: null|Closure(int): ?string, expand: null|Closure(int): array<class-string<Model>, list<int>>}>
     */
    private array $subjects = [];

    /**
     * Reverse index, class name → short key. Kept alongside rather than derived on
     * every lookup: resolving a page of fifty activity rows asks this question fifty
     * times, once per row.
     *
     * @var array<class-string<Model>, string>
     */
    private array $keys = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  string  $label  Persian, singular, as a shopkeeper would say it
     * @param  int  $position  ordering within the filter dropdown; ties fall back to
     *                         registration order, which is module discovery order and
     *                         therefore a directory listing — so set it deliberately
     * @param  null|Closure(int): ?string  $namer  names one record of this kind, for the
     *                                             heading on a record's history. Registered here rather
     *                                             than in DocumentRegistry so a module declares a subject
     *                                             once; the registry that knows a kind exists is the one
     *                                             that should know what to call it.
     */
    public function register(
        string $key,
        string $modelClass,
        string $label,
        int $position = 100,
        ?Closure $namer = null,
        ?Closure $expand = null,
    ): void {
        $this->subjects[$key] = [
            'class' => $modelClass,
            'label' => $label,
            'position' => $position,
            'namer' => $namer,
            'expand' => $expand,
        ];

        $this->keys[$modelClass] = $key;
    }

    /**
     * What to call record `$id` of kind `$key` — «آیفون ۱۵ پرو», not «#4417».
     *
     * Runs the owning module's closure, which queries under RLS like any other read,
     * so a record belonging to another shop resolves to null here for the same reason
     * its activity rows are invisible: there is nothing to find.
     *
     * Returns null for an unregistered kind, a subject that never supplied a namer,
     * or a record that has since been deleted. The caller renders the id in that
     * case — a poor name, but a heading, and «تاریخچه» for a deleted product is
     * exactly when its history is worth reading.
     */
    public function nameFor(string $key, int $id): ?string
    {
        $namer = $this->subjects[$key]['namer'] ?? null;

        if ($namer === null) {
            return null;
        }

        try {
            return $namer($id);
        } catch (Throwable) {
            // A namer is a convenience on a read-only screen. It must not be able to
            // take down the audit log, which is the screen people open when something
            // has already gone wrong.
            return null;
        }
    }

    /**
     * @return class-string<Model>|null null for an unknown key, which callers must
     *                                  treat as "no such filter" rather than "no
     *                                  filter" — see ActivityLogController.
     */
    public function classFor(string $key): ?string
    {
        return $this->subjects[$key]['class'] ?? null;
    }

    public function keyFor(?string $modelClass): ?string
    {
        return $modelClass === null ? null : ($this->keys[$modelClass] ?? null);
    }

    /**
     * The Persian label for a stored `subject_type`, falling back to the bare class
     * name so an un-registered subject still renders as something rather than blank.
     */
    public function labelFor(?string $modelClass): ?string
    {
        if ($modelClass === null) {
            return null;
        }

        $key = $this->keys[$modelClass] ?? null;

        return $key !== null ? $this->subjects[$key]['label'] : class_basename($modelClass);
    }

    /**
     * Records whose entries belong on `$key` `$id`'s history page, beside its own.
     *
     * ## Why a record's history is not only its own rows
     *
     * A price change is logged against the **variant**, because that is what the price
     * belongs to. A shopkeeper opens the history of the **product**, because that is
     * the screen they were on and «این گوشی» is one thing to them. Without this, the
     * «تاریخچه» link built to answer «کی این قیمت را عوض کرد؟» opens a page with every
     * kind of change on it except that one.
     *
     * It is not an edge case: ADR 0013 makes one product with one no-axis variant the
     * shape of every imported row, so for very nearly every product in the system the
     * product and the variant are the same object seen from two tables.
     *
     * The owning module supplies the relationship, so the audit-log controller keeps
     * not knowing that a product has variants (ADR 0003).
     *
     * @return array<class-string<Model>, list<int>> keyed by class, empty when the
     *                                               subject declared no expansion
     */
    public function relatedTo(string $key, int $id): array
    {
        $expand = $this->subjects[$key]['expand'] ?? null;

        if ($expand === null) {
            return [];
        }

        try {
            return array_filter($expand($id), static fn (array $ids): bool => $ids !== []);
        } catch (Throwable) {
            // Same reasoning as nameFor(): an expansion is an improvement to a
            // read-only screen and must not be able to take it down. Losing the
            // related rows shows less history, never someone else's.
            return [];
        }
    }

    /**
     * Options for the filter dropdown, ordered.
     *
     * @return list<array{key: string, label: string}>
     */
    public function options(): array
    {
        $subjects = $this->subjects;

        uasort($subjects, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_values(array_map(
            fn (array $subject, string $key): array => ['key' => $key, 'label' => $subject['label']],
            $subjects,
            array_keys($subjects),
        ));
    }
}
