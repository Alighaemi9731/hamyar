<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Enums\PartyKind;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\PartyImporter;
use App\Support\Money;
use App\Support\Spreadsheet\SpreadsheetReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The customer-import wizard: upload, map columns, dry run, commit.
 *
 * The uploaded file lives between requests under a tenant-scoped path with a random
 * name, and the client is handed only that name. It never sends a path — a client that
 * chooses which file the server reads is a client that can read any file the server
 * can.
 */
final class PartyImportController extends Controller
{
    private const DISK = 'local';

    public function create(SpreadsheetReaders $readers): Response
    {
        $this->authorize('import', Party::class);

        return Inertia::render('CRM::Import/Index', [
            'fields' => PartyImporter::FIELDS,
            'kinds' => array_map(
                static fn (PartyKind $kind): array => ['value' => $kind->value, 'label' => $kind->labelFa()],
                PartyKind::cases()
            ),
            'extensions' => $readers->extensions(),
        ]);
    }

    /**
     * Step one: take the file, return its headers and a few sample rows.
     */
    public function analyse(Request $request, PartyImporter $importer, SpreadsheetReaders $readers, TenantContext $context): JsonResponse
    {
        $this->authorize('import', Party::class);

        $request->validate([
            'file' => ['required', 'file', 'max:8192'],
        ], [
            'file.required' => 'فایل فهرست مشتریان را انتخاب کنید.',
            'file.max' => 'حجم فایل نباید از ۸ مگابایت بیشتر باشد.',
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! $readers->supports($extension)) {
            return response()->json([
                'message' => 'این نوع فایل پشتیبانی نمی‌شود. فایل را با فرمت CSV ذخیره کنید.',
            ], 422);
        }

        // Random name, tenant-scoped directory: the token the client gets back cannot
        // be pointed at another shop's upload, and the extension is ours, not theirs.
        $token = Str::uuid()->toString().'.'.$extension;
        $file->storeAs($this->directory($context), $token, self::DISK);

        $path = Storage::disk(self::DISK)->path($this->directory($context).'/'.$token);

        try {
            $preview = $importer->preview($path, $extension);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => 'فایل خوانده نشد. آیا فایل سالم است؟'], 422);
        }

        return response()->json([
            'token' => $token,
            'headers' => $preview['headers'],
            'samples' => $preview['samples'],
            'mapping' => $importer->guessMapping($preview['headers']),
        ]);
    }

    /**
     * Step two: what would happen, without doing it.
     */
    public function dryRun(Request $request, PartyImporter $importer, TenantContext $context): JsonResponse
    {
        $this->authorize('import', Party::class);

        [$path, $extension, $mapping, $kind, $unit] = $this->resolve($request, $context);

        $result = $importer->analyse($path, $extension, $mapping, $kind, $unit);

        return response()->json([
            'counts' => $result['counts'],
            // Enough to review; a 500-row report nobody scrolls is not a report.
            'rows' => array_slice($result['rows'], 0, 200),
            'truncated' => count($result['rows']) > 200,
        ]);
    }

    /**
     * Step three: commit, in one transaction.
     */
    public function store(Request $request, PartyImporter $importer, TenantContext $context): RedirectResponse
    {
        $this->authorize('import', Party::class);

        [$path, $extension, $mapping, $kind, $unit] = $this->resolve($request, $context);

        $result = $importer->import($path, $extension, $mapping, $kind, $unit);

        // The upload has done its job; leaving customer lists on disk is a liability
        // nobody remembers to clean up.
        Storage::disk(self::DISK)->delete($this->directory($context).'/'.basename($path));

        $created = $result['counts'][PartyImporter::OUTCOME_CREATE];
        $updated = $result['counts'][PartyImporter::OUTCOME_UPDATE];

        return redirect()
            ->route('crm.parties.index')
            ->with('success', "{$created} طرف حساب ثبت و {$updated} مورد تکمیل شد.");
    }

    /**
     * The validated file path, mapping and options for a step-two or step-three call.
     *
     * @return array{0: string, 1: string, 2: array<string, int|null>, 3: string, 4: string}
     */
    private function resolve(Request $request, TenantContext $context): array
    {
        $validated = $request->validate([
            // A name, never a path: `basename` below is what makes that true.
            'token' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(PartyKind::class)],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:200'],
        ]);

        // Strips any directory the client tried to smuggle in, so the file can only
        // ever be one this tenant uploaded.
        $token = basename($validated['token']);
        $relative = $this->directory($context).'/'.$token;

        if (! Storage::disk(self::DISK)->exists($relative)) {
            abort(404, 'فایل بارگذاری‌شده پیدا نشد. دوباره بارگذاری کنید.');
        }

        /** @var array<string, int|null> $mapping */
        $mapping = $validated['mapping'];

        if (($mapping['name'] ?? null) === null) {
            abort(422, 'ستون نام باید انتخاب شود.');
        }

        return [
            Storage::disk(self::DISK)->path($relative),
            strtolower(pathinfo($token, PATHINFO_EXTENSION)),
            $mapping,
            $validated['kind'],
            $validated['unit'],
        ];
    }

    private function directory(TenantContext $context): string
    {
        return 'imports/'.($context->id() ?? 0);
    }
}
