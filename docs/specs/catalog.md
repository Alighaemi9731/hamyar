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
