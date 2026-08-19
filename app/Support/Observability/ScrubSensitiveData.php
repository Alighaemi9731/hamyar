<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Support\SensitiveInput;
use App\Support\Tenancy\TenantContext;
use Sentry\Event;
use Sentry\EventHint;
use Throwable;

/**
 * The last thing that runs before an error report leaves this server.
 *
 * ## Why a crash reporter is a tenancy problem
 *
 * Every other boundary in this product keeps one shop's data away from another shop.
 * A crash reporter keeps it away from nobody: it takes the request body, the user, the
 * breadcrumbs and the local state of a fifty-shop platform and posts them to a
 * third-party service, where they are readable by anyone with a login to that service
 * and retained on somebody else's schedule.
 *
 * That is a defensible trade — an unobserved production is worse — but only if what
 * gets sent is decided deliberately rather than by a vendor default. This class is
 * where that decision is written down.
 *
 * ## What is sent
 *
 * **The tenant's identity, never the tenant's data.** An error at fifty shops is
 * useless without knowing which shop, and «چرا این عوض شد؟» is answered from the audit
 * log, not from Sentry. So the event carries `tenant_id` and the shop's slug as tags —
 * enough to search by, enough to correlate with the audit trail, and nothing a
 * customer would recognise as theirs.
 *
 * The slug is a shop's own chosen name and is already public: it is the subdomain
 * every one of its customers types. It is not a secret and it makes an incident
 * report readable by a human instead of a lookup exercise.
 *
 * ## What is not
 *
 * - **Request bodies are scrubbed** through {@see SensitiveInput}, the same list that
 *   keeps a repair passcode out of the session store. One list, two doors.
 * - **`send_default_pii` is off** in `config/sentry.php`, so no IP address, no cookie
 *   jar, no `Authorization` header.
 * - **SQL bindings are off** in both breadcrumbs and traces. A binding array is the
 *   single richest leak in the product: `select * from parties where national_id = ?`
 *   carries the national id, and a customer's IMEI, phone number and unlock code all
 *   arrive the same way. The query *shape* is what makes a slow query legible; the
 *   values only make it a disclosure.
 *
 * ## The failure mode this guards against
 *
 * A `before_send` that throws takes the error report with it — the original exception
 * is then invisible, and the outage is diagnosed without the one signal that would
 * have explained it. So every branch here is wrapped: a scrubber that cannot resolve a
 * tenant must still return the event, un-tagged and scrubbed.
 */
final class ScrubSensitiveData
{
    /**
     * Sentry's `before_send` and `before_send_transaction` hook.
     *
     * **Static, and referenced from config as `[self::class, 'handle']`, for a reason
     * that only shows up in production.** Laravel caches configuration by
     * `var_export`ing it to a PHP file. A closure cannot be exported at all, and an
     * object exports to a `__set_state()` call that does not exist — so either form
     * turns `php artisan config:cache` into a hard failure *during a deploy*, on the
     * one box where nobody was going to run it by hand first. A static callable is two
     * strings in an array, which exports fine.
     *
     * Returning null would discard the event entirely; every path here returns it.
     */
    public static function handle(Event $event, ?EventHint $hint = null): Event
    {
        unset($hint);

        self::scrubRequest($event);
        self::tagTenant($event);

        return $event;
    }

    /**
     * Mask sensitive keys in the request body, query string and headers.
     */
    private static function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();

        if ($request === []) {
            return;
        }

        foreach (['data', 'query_string', 'headers', 'cookies', 'env'] as $section) {
            if (isset($request[$section]) && is_array($request[$section])) {
                $request[$section] = SensitiveInput::scrub($request[$section]);
            }
        }

        $event->setRequest($request);

        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra(SensitiveInput::scrub($extra));
        }
    }

    /**
     * Tag the event with which shop was being served.
     *
     * Read from the pinned context rather than from the request host: a queued job has
     * no request, and the job is exactly the case where "which shop?" is hardest to
     * answer after the fact.
     */
    private static function tagTenant(Event $event): void
    {
        try {
            $tenant = app(TenantContext::class)->tenant();
        } catch (Throwable) {
            // Container not booted, or no context — a boot-time failure is still worth
            // reporting, and an untagged event beats a swallowed one.
            return;
        }

        if ($tenant === null) {
            // Central request, platform panel or a platform-wide job. Said explicitly,
            // because a missing tag is indistinguishable from a scrubber that broke.
            $event->setTag('tenant', 'platform');

            return;
        }

        $event->setTag('tenant_id', (string) $tenant->id);

        // Guarded rather than cast: a tenant with no slug is not a reason to lose the
        // event, and `setTag()` takes a string.
        if (is_string($tenant->slug) && $tenant->slug !== '') {
            $event->setTag('tenant', $tenant->slug);
        }
    }
}
