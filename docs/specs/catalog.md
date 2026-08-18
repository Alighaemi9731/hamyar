# Catalog

**Phase 3** · Module `app/Modules/Catalog`

## Purpose

What the shop sells: categories, brands, models, variants and prices. Deliberately
separate from [Inventory](inventory.md) — Catalog says a thing *exists and costs this
much*; Inventory says *where it is and what happened to it*.

## Data

- `categories` — self-referencing tree (`parent_id`), `name`, `slug`, `position`.
- `brands` — `name`, `name_fa`, `logo_media_id`.
- `products` — `category_id`, `brand_id`, `name`, `sku`, **`type`**
  (`standard` · `serialized`), `is_active`, `low_stock_threshold`, `description`.
- `product_variants` — `product_id`, attribute values (colour / storage / RAM),
  `barcode`, `sku`, `is_active`.
- `price_levels` — seeded: `consumer` (مصرف‌کننده), `reseller` (همکار),
  `vip` (همکار ویژه). Tenants may add more.
- `product_prices` — `product_variant_id`, `price_level_id`, `price` (rial),
  `effective_from`.

`type` is the fork in the road: `serialized` products get `product_units` rows and
per-unit costs; `standard` products get quantities and weighted-average cost.

## Behaviour

### Variants

Generated from an attribute matrix — a model with 3 colours × 2 storage sizes yields
6 variants in one action, each with its own barcode and price. Regenerating never
deletes a variant that has stock or history; it deactivates it.

### Barcodes

Either the manufacturer's, or generated. Unique per tenant. The POS scan box resolves
a barcode, an internal SKU, or an IMEI — the salesperson does not choose which.

### Prices

Held per variant per price level. The party's level decides which applies; an override
at the till needs a permission.

Bulk update by percentage or fixed amount, filterable by category, brand or supplier,
with a **preview of affected rows before applying**. Iranian pricing changes weekly;
this screen gets used constantly and must never surprise anyone.

Price history is retained so profit reports can be re-derived.

### Products import (Phase 11b)

Three layers, all required: a **downloadable template**, a **column-mapping screen**, and
a **dry run that writes nothing until confirmed**. Structurally the party import
([CRM](crm.md)), which it reuses — `SpreadsheetReaders`, the tenant-scoped upload token,
the analyse → dry-run → commit flow.

**One row is one product and one `options: []` variant.** Grouping is opt-in, never
inferred from a product name. Matching on re-import is barcode → SKU → no match. The
reasoning, and the reversibility argument that decided it, are in
[ADR 0013](../adr/0013-flat-product-import.md).

**The currency unit is a required choice with no default and no inference.** A price
column is quoted in toman most of the time and in rial the rest, nothing in the file says
which, and guessing wrong is a ten-fold error across the whole catalog.

#### Quantity is not imported

Real exports all carry a «موجودی» column and an operator will expect it to load. It does
not, and the screen says so: the column appears in the mapping list **greyed, labelled
«وارد نمی‌شود»**, with a one-line pointer to the path that is correct — an opening
purchase receipt, or a stock count. Silence here reads as a bug; a label reads as a
decision.

The reason is golden rule 3. Stock is a ledger, so an opening quantity has to be written
as a `stock_movements` row, and that needs a warehouse and a unit cost the file does not
carry. For `serialized` products it is meaningless anyway: twelve handsets are twelve
`product_units` with twelve IMEIs, not a quantity of 12.

#### ی/ک normalisation is code-page repair, not tidying

The single most surprising thing found while building this, and the reason the rule is
written here rather than assumed.

**windows-1256 cannot represent Persian.** Verified character by character:

| character | survives windows-1256 |
|---|---|
| Persian digits ۱۲۳ · Arabic-Indic ١٢٣ · separator ٬ | **no** |
| Persian yeh **ی** (U+06CC) | **no** |
| Arabic yeh **ي** (U+064A) | yes |
| Persian kaf **ک** (U+06A9) · Arabic kaf **ك** (U+0643) | yes |

Three consequences, none of them guessable:

- A windows-1256 file **cannot contain** «گوشی». It physically contains «گوشي», every
  time. So normalising ی and ک is not cleaning up after sloppy typing — it is repairing a
  lossy code page, and skipping it means every product name imported from legacy software
  fails to match what the shop later types into the search box.
- Persian kaf survives while Persian yeh does not, so the file arrives mixing Persian kaf
  with Arabic yeh — a combination no human types. That makes it a reliable fingerprint for
  **detecting** a cp1256 origin rather than asking the operator what encoding they have.
- **Digit normalisation targets `.xlsx`, not the legacy format.** A cp1256 file always has
  Latin digits, because the code page has no others. This inverts the intuition that the
  old format is the messy one — for digits, it is the clean one.

The repair is reported on screen, not performed silently: the file chip states the
encoding it detected and that ی/ک were standardised, and the sample rows are the evidence
the operator checks before continuing.

#### Prices are parsed, never stripped

Money goes through `Money::parse()`. An Iranian sheet writes a decimal with a **slash**
(«۱۲۵۰۰۰۰/۰»), and the obvious normalisation — strip every non-digit — concatenates the
fraction onto the amount and lands ten times high, silently. See
[testing.md](../testing.md) for the regression this cost in the party import.

## Screens

Category tree · brands · product list with type filter · product editor with the
variant matrix · price-level grid · bulk price update with preview · barcode/label
printing (shared with Inventory).

## Events

Emits: `ProductCreated`, `PriceChanged`, `BulkPriceUpdateApplied`.

## Acceptance

- The variant matrix produces the right variant count and no duplicates.
- Barcode uniqueness is enforced per tenant.
- Price-level resolution: the right price for the right party, and an override needs
  the permission.
- Bulk update preview matches exactly what is applied.
- Deactivating a variant with stock is prevented or warned, never silent.
- Cross-tenant isolation on every endpoint.

## Out of scope

Product bundles/kits. Supplier-specific catalogues. Automated price feeds.
