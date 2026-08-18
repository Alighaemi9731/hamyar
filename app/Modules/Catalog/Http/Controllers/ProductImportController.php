<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImporter;
use App\Modules\Catalog\Services\ProductImportTemplate;
use App\Support\Digits;
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
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The products-import wizard: template, upload, map columns, dry run, commit.
 *
 * The uploaded file lives between requests under a tenant-scoped path with a random
 * name, and the client is handed only that name. It never sends a path — a client that
 * chooses which file the server reads is a client that can read any file the server can.
 * Same shape as the party import, deliberately: two upload wizards that differ in their
 * security handling is one wizard nobody has audited.
 */
final class ProductImportController extends Controller
{
    private const DISK = 'local';

    /** How many report rows reach the browser. A 2,000-row list nobody scrolls is not a report. */
    private const REPORT_LIMIT = 200;

    public function create(SpreadsheetReaders $readers): Response
    {
        $this->authorize('import', Product::class);

        return Inertia::render('Catalog::Import/Index', [
            'fields' => ProductImporter::FIELDS,
            'ignoredFields' => ProductImporter::IGNORED_FIELDS,
            'types' => array_map(
                static fn (ProductType $type): array => ['value' => $type->value, 'label' => $type->labelFa()],
                ProductType::cases()
            ),
            'extensions' => $readers->extensions(),
        ]);
    }

    /**
     * The blank sheet, for a shop that would rather fill one in than reshape their export.
     */
    public function template(ProductImportTemplate $template): BinaryFileResponse
    {
        $this->authorize('import', Product::class);

        /** @var BinaryFileResponse $download */
        $download = Excel::download($template->sheet(), 'قالب-ورود-کالاها.xlsx');

        return $download;
    }

    /**
     * Step one: take the file, return its headers, a few sample rows, and what we can
     * tell about the file itself.
     */
    public function analyse(Request $request, ProductImporter $importer, SpreadsheetReaders $readers, TenantContext $context): JsonResponse
    {
        $this->authorize('import', Product::class);

        $request->validate([
            'file' => ['required', 'file', 'max:8192'],
        ], [
            'file.required' => 'فایل کالاها را انتخاب کنید.',
            'file.max' => 'حجم فایل نباید از ۸ مگابایت بیشتر باشد.',
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! $readers->supports($extension)) {
            return response()->json([
                'message' => 'این نوع فایل پشتیبانی نمی‌شود. فایل اکسل (xlsx یا xls) یا CSV بفرستید.',
            ], 422);
        }

        $token = Str::uuid()->toString().'.'.$extension;
        $file->storeAs($this->directory($context), $token, self::DISK);

        $path = Storage::disk(self::DISK)->path($this->directory($context).'/'.$token);

        try {
            $preview = $importer->preview($path, $extension);
        } catch (RuntimeException) {
            return response()->json(['message' => 'فایل خوانده نشد. آیا فایل سالم است؟'], 422);
        }

        return response()->json([
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
            'headers' => $preview['headers'],
            'samples' => $preview['samples'],
            'mapping' => $importer->guessMapping($preview['headers']),
            // Announced, not performed silently: the operator is told the file went
            // through a legacy code page and the sample rows are the evidence.
            'encoding' => $preview['encoding'],
            'repairedText' => $preview['repaired_text'],
        ]);
    }

    /**
     * Step two: what would happen, without doing it.
     */
    public function dryRun(Request $request, ProductImporter $importer, TenantContext $context): JsonResponse
    {
        $this->authorize('import', Product::class);

        [$path, $extension, $mapping, $unit, $type] = $this->resolve($request, $context);

        $result = $importer->analyse($path, $extension, $mapping, $unit, $type);

        return response()->json([
            'counts' => $result['counts'],
            'rows' => array_slice($result['rows'], 0, self::REPORT_LIMIT),
            'truncated' => count($result['rows']) > self::REPORT_LIMIT,
            'total' => count($result['rows']),
        ]);
    }

    /**
     * Step three: commit, in one transaction.
     */
    public function store(Request $request, ProductImporter $importer, TenantContext $context): RedirectResponse
    {
        $this->authorize('import', Product::class);

        [$path, $extension, $mapping, $unit, $type] = $this->resolve($request, $context);

        $result = $importer->import($path, $extension, $mapping, $unit, $type);

        // The upload has done its job; leaving a shop's price list on disk is a
        // liability nobody remembers to clean up.
        Storage::disk(self::DISK)->delete($this->directory($context).'/'.basename($path));

        $created = $result['counts'][ProductImporter::OUTCOME_CREATE];
        $updated = $result['counts'][ProductImporter::OUTCOME_UPDATE];

        return redirect()
            ->route('catalog.products.index')
            ->with('success', Digits::toPersian((string) $created).' کالا ثبت و '
                .Digits::toPersian((string) $updated).' کالا به‌روزرسانی شد.');
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
            // No default anywhere in the stack, so a client that omits it is rejected
            // rather than served a guess worth ten times the catalogue.
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
            'type' => ['required', Rule::enum(ProductType::class)],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:200'],
        ], [
            'unit.required' => 'واحد مبلغ را انتخاب کنید.',
            'unit.in' => 'واحد مبلغ را انتخاب کنید.',
        ]);

        $token = basename($validated['token']);
        $relative = $this->directory($context).'/'.$token;

        if (! Storage::disk(self::DISK)->exists($relative)) {
            abort(404, 'فایل بارگذاری‌شده پیدا نشد. دوباره بارگذاری کنید.');
        }

        /** @var array<string, int|null> $mapping */
        $mapping = $validated['mapping'];

        if (($mapping['name'] ?? null) === null) {
            abort(422, 'ستون نام کالا باید انتخاب شود.');
        }

        return [
            Storage::disk(self::DISK)->path($relative),
            strtolower(pathinfo($token, PATHINFO_EXTENSION)),
            $mapping,
            $validated['unit'],
            $validated['type'],
        ];
    }

    private function directory(TenantContext $context): string
    {
        return 'imports/'.($context->id() ?? 0);
    }
}
