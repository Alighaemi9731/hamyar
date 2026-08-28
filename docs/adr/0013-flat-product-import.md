# ADR 0013 — A product import row is one product and one no-axis variant

**Status:** accepted · Phase 11b · decided 2026-08-18 (Checkpoint 2)

## Context

~40–50 of the owner's own customers evaluate Hamyar concurrently at launch, and each
arrives with an existing catalog in Excel, exported from Iranian shop software (هلو,
سپیدار, محک, پارمیس) or maintained by hand. A catalog that cannot be loaded is an
evaluator lost on day one, which is why the products import is onboarding-blocking
rather than a convenience.

Those files are **flat**: one row per sellable thing, carrying a name, sometimes a
barcode, a price, and a quantity. The name is one string with everything baked into it
— «آیفون ۱۵ پرو مکس ۲۵۶ مشکی» — not a product name beside a colour column and a
storage column.

Our schema is not flat. A product has variants, and the question is what a flat row
becomes.

## Decision

**One row becomes one product and exactly one variant with `options: []`.**

Grouping several rows into one product with real variants is **opt-in**: it happens
only when the operator maps a product-name column *and* at least one option column,
because the file genuinely has them. The importer never infers a grouping by parsing a
product name.

Matching on re-import is a ladder — **barcode → SKU → no match** — mirroring the party
import's mobile → national-id ladder. Both columns are unique per tenant among live
rows (partial unique indexes on `product_variants`), so both are safe keys. A row with
neither cannot be matched, so re-importing that file duplicates those rows; the dry run
reports that count before anything is written.

## Why

**The variant is the universal anchor, so every row must produce one.** Confirmed
against the schema: `stock_movements`, `product_prices`, `product_units`,
`sales_invoice_items`, `purchase_invoice_items`, `ticket_parts` and
`stock_reservations` all carry `product_variant_id`. Not one carries `product_id`. A
product with no variant cannot be stocked, priced or sold — it is a row nothing can
reach. (Creating a product in the UI does not make one either; it redirects to
«حالا ویژگی‌ها و تنوع‌ها را بسازید».) So "one row → at least one variant" is not a
preference, it is the condition for the import producing anything at all.

**A single `options: []` variant is already the house convention** for anything without
axes. `DemoShopSeeder` does exactly this for the charger and the case, each carrying its
own barcode, SKU and price.

**And the ruling rationale — the asymmetry in what a mistake costs.**

| | wrong how | cost of being wrong |
|---|---|---|
| **Flat** | two colours of one phone become two products | an afternoon of tidying. Both sell, stock and price correctly in the meantime |
| **Inferred** | two unrelated products are merged, or one product is split on a word that was not an option | **permanent** |

Grouped-wrong is unrecoverable in the precise sense: once stock movements and invoice
lines reference those variants, splitting them is not an operation this system has, and
`VariantMatrix` deliberately never deletes a variant with history ([catalog
spec](../specs/catalog.md)). The shop would be left with a catalog it cannot correct and
a set of closed months it must not.

Flat-and-wrong is a data-entry annoyance. Grouped-wrong is a corrupted catalog. When one
side of a guess is recoverable and the other is not, the importer does not guess — and it
would have guessed *quietly*, which is the failure shape this codebase has now written
four separate rules against.

## Consequences

- A shop importing a phone catalog gets a flat product list, not a variant matrix. This
  is stated on the mapping screen rather than discovered: the operator is told rows will
  not be grouped, and offered the option columns if their file has them.
- Merging products after the fact is a shop-side task with no tooling in 11b. If it turns
  out evaluators want it, it is a post-launch backlog item — and it is *possible*, which
  is the whole point of choosing this direction.
- The opt-in grouping path shares `VariantMatrix`, so a grouped import is the same code
  the product editor already uses.

## Alternatives rejected

**Parse the name into product + options.** Regex or heuristics over «آیفون ۱۵ پرو مکس
۲۵۶ مشکی». Rejected on the asymmetry above: it is a guess whose failure is permanent and
silent.

**Require the file to have option columns.** Correct-by-construction and useless — real
exports do not have them, so the import would reject the files it exists to accept.

**One row = one variant, always grouped by exact product-name match.** Subtler, and still
wrong: it merges «قاب محافظ شفاف» from two different brands into one product, and the
merge is just as permanent.
