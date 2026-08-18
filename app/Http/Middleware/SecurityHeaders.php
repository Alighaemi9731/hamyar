<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers that decide what a browser will let this page do.
 *
 * Everything else in this application defends the server: RLS decides which rows a
 * query returns, policies decide which routes an actor reaches, the passcode is
 * encrypted at rest. None of that helps once markup is running in the shop's browser
 * under the shop's session — at that point the attacker *is* the operator, holds their
 * cookie, and every server-side check passes because it is genuinely them asking.
 *
 * These headers are the layer that makes that harder to arrange and cheaper to survive.
 *
 * ## The policy is written against what this application actually does
 *
 * A copied-in CSP is worse than none: it either breaks the product on the first screen
 * nobody tested, or it is loosened directive by directive until it permits everything
 * and is kept only because deleting it would look careless. Each relaxation below names
 * the code that needs it, so a future reader can check whether that is still true
 * rather than guess why it is there.
 */
final class SecurityHeaders
{
    /**
     * Where `npm run dev` serves modules from.
     *
     * No `[::1]` form: a bracketed IPv6 literal is not a valid CSP source expression,
     * and a browser meeting one **discards that source and keeps the rest of the
     * directive** — so the policy silently narrows rather than failing. Caught on the
     * browser walk, where it cost three console errors and nothing else; on a directive
     * without a `localhost` beside it, it would have cost the whole directive.
     */
    private const VITE_DEV_ORIGINS = 'http://localhost:5173 http://127.0.0.1:5173';

    /** ...and where its hot-reload socket lives. */
    private const VITE_DEV_SOCKETS = 'ws://localhost:5173 ws://127.0.0.1:5173';

    public function handle(Request $request, Closure $next): Response
    {
        // BEFORE the response is rendered: the nonce has to exist while Blade runs, or
        // `Vite::cspNonce()` in the layout returns null and the tags it stamps carry
        // nothing for the policy below to match.
        Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // A repair-tracking link is handed to a customer and lands in SMS, then in a
        // browser, then possibly in a referrer header on the way to wherever they go
        // next. The token in that path is a bearer credential.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // `frame-ancestors` in the policy supersedes this everywhere modern; kept for
        // the browsers that read only the old header. Nothing here is meant to be
        // framed — a POS screen in an iframe is a clickjacking target.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Nothing in the product uses a camera, microphone or location. Naming them is
        // how a future dependency that quietly asks for one gets refused rather than
        // granted on the operator's behalf.
        //
        // `camera=(self)` rather than `()`: repair intake photographs a customer's
        // device with `capture="environment"` on a file input, which is the flagship
        // module's first screen (Repairs/Tickets/Intake.tsx).
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(), interest-cohort=()',
        );

        // Only on a connection that is already HTTPS, and never in dev. Sent over
        // plain http it is ignored by the spec; sent from a local https proxy it would
        // pin `*.localhost` to https in the developer's browser for a year, which is a
        // day of confusion for no protection.
        if ($request->secure() && app()->environment('production', 'staging')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('Content-Security-Policy', $this->policy());

        return $response;
    }

    /**
     * @return string the policy, as one header value
     */
    private function policy(): string
    {
        $nonce = Vite::cspNonce();
        $script = "'self'".($nonce !== null ? " 'nonce-{$nonce}'" : '');

        /*
        | Keyed by directive rather than built as a list, because the development
        | branch below has to *replace* two of these and positional edits into an array
        | of strings are how a policy quietly loses a directive during a refactor.
        */
        $policy = [
            'default-src' => "'self'",

            // Stops an injected `<base href>` from re-pointing every relative URL on
            // the page — including the form actions below — at somebody else's host.
            'base-uri' => "'self'",

            // No plugins, and no framing. `frame-ancestors` is the one that actually
            // stops clickjacking on current browsers.
            'object-src' => "'none'",
            'frame-ancestors' => "'none'",
            'frame-src' => "'none'",

            // A form injected into a page cannot post the shop's session, or a
            // customer's details, anywhere but back here.
            'form-action' => "'self'",

            'script-src' => $script,

            /*
            | The one real relaxation, and it is `style-src`.
            |
            | Seven components set a style attribute for a value that is genuinely
            | computed rather than themed: bar-chart's column heights, print-layout's
            | sheet width, and the label sheet's millimetre dimensions. Those are DOM
            | style attributes, which `style-src` governs, and a policy without
            | 'unsafe-inline' silently flattens every chart and mis-sizes every printed
            | label sheet.
            |
            | Accepted because the risk it carries is narrow: injected CSS can restyle
            | a page, and cannot execute. The directive that stops code — `script-src`
            | — keeps its nonce.
            */
            'style-src' => "'self' 'unsafe-inline'",

            // `data:` for the barcodes and QR codes receipts and tracking links are
            // printed with; `blob:` for the photo previews repair intake shows before
            // upload (URL.createObjectURL).
            'img-src' => "'self' data: blob:",

            // Self-hosted Vazirmatn and Estedad. No font CDN, deliberately: a font
            // request is a per-page beacon to a third party, and the licence is ours.
            'font-src' => "'self'",

            'connect-src' => "'self'",
            'media-src' => "'self'",
            'worker-src' => "'self' blob:",
            'manifest-src' => "'self'",
        ];

        if (app()->environment('production', 'staging')) {
            // Not `block-all-mixed-content`, which is deprecated: this upgrades a
            // stray http asset instead of failing it.
            $policy['upgrade-insecure-requests'] = '';
        } else {
            /*
            | Development only. `npm run dev` serves modules and its hot-reload socket
            | from Vite on another port, which is a different origin to the browser, and
            | React Refresh evaluates the modules it swaps in.
            |
            | Relaxed here rather than switched off wholesale, so the policy a developer
            | browses under is the same shape as production's. A CSP first met in
            | staging is a CSP debugged in staging.
            */
            $policy['script-src'] = $script." 'unsafe-eval' ".self::VITE_DEV_ORIGINS;
            $policy['connect-src'] = "'self' ".self::VITE_DEV_ORIGINS.' '.self::VITE_DEV_SOCKETS;
        }

        return implode('; ', array_map(
            static fn (string $sources, string $directive): string => trim("{$directive} {$sources}"),
            $policy,
            array_keys($policy),
        ));
    }
}
