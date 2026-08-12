<?php

declare(strict_types=1);

use App\Modules\Files\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Files — web routes
|--------------------------------------------------------------------------
|
| One route, and only a development machine uses it: MinIO and S3 sign their own URLs,
| so in production the bytes never pass through PHP. The local driver cannot sign, and
| falling back to a public path there would build a habit that only holds in production.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user'])
    ->get('/files/{attachment}', [AttachmentController::class, 'show'])
    ->whereNumber('attachment')
    ->name('files.show');
