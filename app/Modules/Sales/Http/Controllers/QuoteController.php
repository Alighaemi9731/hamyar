<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\ConvertQuote;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * پیش‌فاکتورها.
 *
 * A quote is a priced promise with a number on it, handed to a customer who then goes
 * away to think about it. It moves no stock, posts nothing to the ledger, and reserves
 * nothing — which is exactly why the list has to show how old each one is. A quote from
 * five weeks ago is quoting prices that no longer exist.
 */
final class QuoteController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly BranchAccess $branches) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SalesInvoice::class);

        /** @var User $user */
        $user = $request->user();

        $term = trim($request->string('q')->value());

        $quotes = SalesInvoice::query()
            ->with(['party:id,name', 'branch:id,name', 'convertedTo:id,number'])
            ->where('type', SalesInvoice::TYPE_QUOTE)
            ->tap(fn ($query) => $this->branches->constrain($query, $user))
            ->when($request->boolean('open'), fn ($query) => $query->whereNull('converted_to_id'))
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('number', 'ilike', "%{$term}%")
                    ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', "%{$term}%"));
            }))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Sales::Quotes/Index', [
            'quotes' => [
                'rows' => array_map(fn (SalesInvoice $quote): array => [
                    'id' => $quote->id,
                    'number' => $quote->number,
                    'created_at' => $quote->created_at?->toIso8601String(),
                    'party_name' => $quote->party?->name,
                    'branch_name' => $quote->branch->name,
                    'total' => Money::toArray($quote->total),
                    'converted_to' => $quote->convertedTo === null ? null : [
                        'id' => $quote->convertedTo->id,
                        'number' => $quote->convertedTo->number,
                    ],
                ], $quotes->items()),
                'links' => $quotes->linkCollection()->toArray(),
                'total' => $quotes->total(),
            ],
            'filters' => [
                'q' => $term,
                'open' => $request->boolean('open'),
            ],
            'can' => [
                'create' => $user->can('sales.create'),
            ],
        ]);
    }

    /**
     * Turn a quote into a draft invoice the till can finalise.
     */
    public function convert(Request $request, SalesInvoice $invoice, ConvertQuote $converter): RedirectResponse
    {
        $this->authorize('update', $invoice);

        try {
            $created = $converter->convert($invoice, $request->user()?->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['quote' => $exception->getMessage()]);
        }

        // Straight into the till rather than to the invoice page: a converted quote is
        // a basket somebody is about to take payment for, and the next thing they need
        // is the payment box, not a summary.
        return redirect()
            ->route('sales.pos.resume', $created)
            ->with('success', "پیش‌فاکتور {$invoice->number} به فاکتور تبدیل شد.");
    }
}
