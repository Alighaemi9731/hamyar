<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Put a model in the audit log, with this project's defaults.
 *
 * A thin wrapper over spatie's `LogsActivity`, and the thinness is the point — what it
 * adds is a **default that is safe**, so that auditing a model is one `use` line and
 * cannot accidentally be the line that leaks a passcode.
 *
 * spatie's own default (`LogOptions::defaults()`) logs nothing until told what to
 * watch; the common shortcut is `->logAll()` or `->logFillable()`, and either of those
 * on a model with a secret field writes the secret. Here the watched set is
 * **fillable minus tenancy minus secrets**, with the secret list derived from the
 * model's own `$hidden` and encrypted casts ({@see Redactor}).
 *
 * `tenant_id` is excluded because it never changes and is not a fact anyone audits;
 * it is on `$fillable` only so the tenancy trait can fill it.
 *
 * A model with different needs overrides `auditedAttributes()`. A model that needs
 * different *options* overrides `getActivitylogOptions()` outright, as
 * {@see \App\Modules\Identity\Models\User} does.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->auditedAttributes())
            // Only what changed, and only when something did — a row recording that
            // nothing happened is noise in the one place noise is expensive, because
            // the reader is scanning for the one change that explains a problem.
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(self::describeEvent(...));
    }

    /**
     * The stored description, in Persian.
     *
     * spatie's default is the bare event name, so a shop's audit log filled up with
     * rows saying «updated». That is not only a display problem the viewer could
     * paper over at render time: `description` is the column the free-text filter
     * searches, so an owner typing «ویرایش» into a Persian screen searching Persian
     * data would have matched nothing at all.
     *
     * The words are here rather than in `lang/fa` because they are written by a model
     * event with no request and no locale behind it, and an audit row must read the
     * same in six months as it did when it was written — a stored description that
     * changes meaning when a translation file is edited is not a record.
     */
    private static function describeEvent(string $event): string
    {
        return match ($event) {
            'created' => 'ایجاد شد',
            'updated' => 'ویرایش شد',
            'deleted' => 'حذف شد',
            'restored' => 'بازگردانی شد',
            default => $event,
        };
    }

    /**
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        $secrets = app(Redactor::class)->secretsFor(static::class);

        return array_values(array_diff($this->getFillable(), ['tenant_id'], $secrets));
    }
}
