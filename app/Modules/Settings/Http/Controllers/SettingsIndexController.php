<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Services\SettingsCatalogue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The settings hub — the door the sidebar has been pointing at since it was written.
 */
final class SettingsIndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user() instanceof User ? $request->user() : null;

        return Inertia::render('Settings::Settings/Index', [
            'groups' => SettingsCatalogue::visibleTo($user),
        ]);
    }
}
