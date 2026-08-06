# Files

**Phase 3 onward** · Module `app/Modules/Files`

## Purpose

Attachments, and the fact that many of them are sensitive. This module holds national
ID scans, consent forms for used-device purchases, device photos taken at repair
intake, and signed delivery receipts. A leak here is a privacy incident, not an
inconvenience.

## Data

Built on spatie/laravel-medialibrary, with tenant scoping added:

- `media` — the standard table plus `tenant_id`, indexed and RLS-protected.
- Storage path is always prefixed `tenants/{tenant_id}/…`, so even a
  misconfigured bucket policy cannot mix two shops' files under one prefix.
- `file_quota_usage` — per tenant bytes used, maintained on upload and delete and
  checked against the plan limit ([platform.md](platform.md)).

## Behaviour

### Access

Nothing is public. Every file is served through a **signed, expiring URL** generated
per request after a policy check on the owning record: you can see the ID scan
attached to a purchase only if you can see the purchase.

Sensitive collections (ID scans, consent forms) additionally require a specific
permission and log every access — who looked at whose national ID, and when.

### Uploads

- Allow-list by MIME type and extension, not a deny-list.
- Images re-encoded on upload, which strips EXIF (including GPS from a phone camera)
  and defeats polyglot files.
- Size limits per collection: 32MB general, smaller for thumbnails.
- Virus scanning is a Phase 11 consideration, noted rather than promised.

### Quotas

Checked before an upload. On exhaustion the behaviour matches the platform soft-lock:
uploads blocked, everything existing still readable and downloadable. A shop that hits
its storage cap must not lose access to the ID scan it needs for a dispute.

### Collections

| Collection | Attached to | Sensitivity |
|---|---|---|
| `unit_documents` | `product_units` | **High** — seller ID, consent form |
| `unit_photos` | `product_units` | Normal |
| `ticket_photos` | `repair_tickets` | Normal |
| `ticket_signature` | `repair_tickets` | Normal |
| `party_documents` | `parties` | **High** |
| `expense_receipts` | `expenses` | Normal |
| `cheque_images` | `cheques` | **High** |
| `tenant_logo` | tenant | Public-safe |

### Deletion

Soft-deleted with the owning record; purged on a retention schedule. Purging a tenant
removes its entire storage prefix — verified as part of the tenant-deletion flow.

## Events

Emits: `FileUploaded`, `FileDeleted`, `QuotaExceeded`, `SensitiveFileAccessed`.

## Acceptance

- Every file URL is signed and expires; an unsigned URL is refused.
- Tenant B cannot fetch tenant A's media by id, even with a valid session.
- Storage keys are always under the tenant's prefix.
- Uploads over quota are blocked while reads keep working.
- EXIF is stripped from uploaded images.
- Access to a sensitive collection requires the permission and writes a log entry.
- Deleting a tenant removes its whole prefix.

## Out of scope

Document OCR. Client-side encryption. Version history on attachments.
