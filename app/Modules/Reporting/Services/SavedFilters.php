<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Models\SavedFilter;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A user's presets for one report screen.
 *
 * Small enough to inline, kept here because every report controller needs the same two lines
 * and the *ordering* is a decision: alphabetical by name, not newest-first. A preset list is
 * a place somebody's eye goes to find a name they already know, and a list that reorders
 * itself every time one is saved makes them read it every time.
 */
final class SavedFilters
{
    /**
     * `Authenticatable`, not `User`: a report route can be reached by a signed-in platform
     * (super-admin) account, whose id belongs to a different table entirely. Narrowing here
     * rather than at each of seven call sites means a platform viewer gets an empty preset
     * list — which is the right answer, since presets belong to a shop's own staff — instead
     * of a row keyed by an id that means something else.
     *
     * @return list<array{id: int, name: string, filters: array<string, string>}>
     */
    public function forReport(?Authenticatable $user, string $reportKey): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $presets = SavedFilter::query()
            ->where('user_id', $user->getKey())
            ->where('report_key', $reportKey)
            ->orderBy('name')
            ->get();

        $shaped = [];

        foreach ($presets as $preset) {
            /** @var int|numeric-string $id */
            $id = $preset->getKey();

            $shaped[] = [
                'id' => (int) $id,
                'name' => $preset->name,
                'filters' => $this->stringMap($preset->filters),
            ];
        }

        return $shaped;
    }

    /**
     * The stored JSON, reduced to the flat string map the screen expects.
     *
     * `filters` is a JSON column: whatever shape was written to it is the shape that comes
     * back, and a row written before a validation rule existed — or edited in a console — is
     * not the request's problem to have caught. Anything that is not a scalar under a string
     * key is dropped rather than trusted, so a malformed preset restores fewer filters
     * instead of putting an array where a query parameter goes.
     *
     * @return array<string, string>
     */
    private function stringMap(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $map = [];

        foreach ($filters as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }
}
