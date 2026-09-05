<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;

/**
 * What a crawler receives: `robots.txt`, `sitemap.xml`, and the structured data on the
 * landing page.
 *
 * ## Why this needs tests at all
 *
 * Every assertion here is about output no human on the team will ever look at. A search
 * result is assembled from it weeks later, on a machine nobody controls, and when it is
 * wrong the symptom is a page that quietly does not appear. There is no screen to open.
 *
 * The structural assertion is the one that has already earned its place: the landing must
 * contain `</head>` and `<body>`. A Blade directive name written inside a Blade comment
 * deleted both from the compiled template — Blade extracts php blocks before it strips
 * comments, so the `@php` in the prose paired with the real `@endphp` and the comment
 * stripper ran forty lines past its own terminator. The route still returned 200 and the
 * page still rendered. See `docs/lessons.md`.
 */
beforeEach(function (): void {
    $this->url = 'http://'.config()->string('app.domain');
});

/* ------------------------------------------------------------ robots.txt -- */

it('disallows the capability URLs a crawler must never index', function (): void {
    $response = $this->get($this->url.'/robots.txt')->assertOk();

    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $body = $response->getContent() ?: '';

    // `/p/`, `/i/` and `/t/` are capability URLs — holding the link IS the permission.
    // The old static robots.txt said `Disallow:` with nothing after it, which allows
    // everything, so a crawler meeting one of these in a referrer was free to index a
    // shop's forwarded price list.
    expect($body)
        ->toContain('Disallow: /p/')
        ->toContain('Disallow: /i/')
        ->toContain('Disallow: /t/')
        // The design gallery and the landing comps are real markup with no `noindex`.
        ->toContain('Disallow: /design');

    // And it points at the sitemap by its routed URL, not a guess.
    expect($body)->toContain('Sitemap: '.route('sitemap'));
});

it('names no host but the configured apex, in either document', function (): void {
    /*
    | Golden rule 1b, asserted on the *rendered* documents.
    |
    | `bin/check-apex-domain` reads source and would catch a literal typed into the route.
    | It cannot catch one arriving through a config default, an `env()` fallback or a
    | helper that resolves somewhere else — and these two files are exactly where such a
    | value ends up quietly published to every crawler that asks.
    |
    | Re-registering the routes under a different apex is not the way to test this: route
    | domains are bound when the route file is evaluated, so a `config()->set()` here
    | changes nothing and the request 404s. Reading the hosts back out is.
    */
    $apex = config()->string('app.domain');

    foreach (['/robots.txt', '/sitemap.xml'] as $path) {
        $body = $this->get($this->url.$path)->assertOk()->getContent() ?: '';

        preg_match_all('#https?://([^/\s<]+)#', $body, $matches);

        expect($matches[1])->not->toBeEmpty("{$path} names no URL at all");

        foreach ($matches[1] as $host) {
            // The sitemap namespace is a schema identifier, not one of our addresses.
            if ($host === 'www.sitemaps.org') {
                continue;
            }

            expect($host)->toBe($apex, "{$path} names {$host}, which is not the apex");
        }
    }
});

/* ----------------------------------------------------------- sitemap.xml -- */

it('serves a sitemap that parses, listing exactly the public pages', function (): void {
    $response = $this->get($this->url.'/sitemap.xml')->assertOk();

    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $body = $response->getContent() ?: '';

    // Parsed, not pattern-matched: a sitemap with one stray `&` is rejected whole by the
    // consumer, and a `toContain` assertion would pass on a document no crawler accepts.
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();

    expect($xml)->toBeInstanceOf(SimpleXMLElement::class, 'the sitemap is not well-formed XML');
    assert($xml instanceof SimpleXMLElement);

    // Iterated, not `iterator_to_array()` — that keys every child by its element name, so
    // three `<url>` siblings collapse into one entry and the assertion below passes on a
    // sitemap listing a single page.
    $locations = [];

    foreach ($xml->url as $url) {
        $locations[] = (string) $url->loc;
    }

    expect($locations)->toEqualCanonicalizing([
        route('welcome'),
        route('legal.terms'),
        route('legal.privacy'),
    ]);
});

/* --------------------------------------------------------- structured data -- */

it('renders a whole document — head closed, body opened', function (): void {
    /*
    | The cheapest assertion in this file and the only one that has caught a real bug.
    |
    | A malformed template can delete the end of the head and the start of the body and
    | still return 200 with a page that renders. Nothing in a content assertion notices,
    | because the content it asserts is still there — it is the structure around it that
    | is gone.
    */
    $html = $this->get($this->url.'/')->assertOk()->getContent() ?: '';

    expect($html)
        ->toContain('</head>')
        ->toContain('<body>')
        ->toContain('</html>');
});

it('describes the product and its real prices as structured data', function (): void {
    $html = $this->get($this->url.'/')->assertOk()->getContent() ?: '';

    $blocks = structuredData($html);

    expect($blocks)->toHaveCount(2, 'expected the product graph and the FAQ');

    $types = array_column(ldGraph($blocks), '@type');

    expect($types)->toContain('Organization')->toContain('SoftwareApplication');

    /*
    | The prices come from `plans`, and this asserts that rather than trusting it.
    |
    | Roadmap 11.4 promises that changing a price is a panel edit and not a deploy. A
    | figure typed into the template would keep that promise on the pricing section and
    | break it in the rich result — the one surface nobody on the team ever looks at, so
    | the drift would be permanent.
    */
    $application = ldNode($blocks, 'SoftwareApplication');
    $offers = $application['offers'];

    expect($offers)->toBeArray();
    assert(is_array($offers));

    $plans = Plan::query()->where('is_public', true)->orderBy('position')->get();

    expect($offers)->toHaveCount($plans->count());

    foreach ($plans as $index => $plan) {
        $offer = $offers[$index];

        expect($offer)->toBeArray();
        assert(is_array($offer));

        expect($offer['price'])->toBe((string) $plan->price)
            ->and($offer['priceCurrency'])->toBe('IRR');
    }
});

it('publishes every rendered question, including the ones that answer «no»', function (): void {
    $html = $this->get($this->url.'/')->assertOk()->getContent() ?: '';

    $faq = ldNode(structuredData($html), 'FAQPage');
    $entries = $faq['mainEntity'];

    expect($entries)->toBeArray();
    assert(is_array($entries));

    expect($entries)->toHaveCount(6);

    $questions = [];

    foreach ($entries as $entry) {
        expect($entry)->toBeArray();
        assert(is_array($entry));

        expect($entry['name'])->toBeString();
        assert(is_string($entry['name']));

        $questions[] = $entry['name'];

        $answer = $entry['acceptedAnswer'];

        expect($answer)->toBeArray();
        assert(is_array($answer));

        expect($answer['text'])->not->toBeEmpty();
    }

    // The HAMTA answer is the one a marketing page would drop, and dropping it would mean
    // promising something in search that the page itself does not promise.
    expect(implode(' ', $questions))->toContain('همتا');
});

it('escapes a plan name that could close the script element', function (): void {
    /*
    | Nothing in the Filament panel stops an operator naming a plan with a `<` in it, and
    | a raw `</script>` inside a JSON-LD block ends the element — everything after it
    | becomes markup, which is both a broken page and a script-injection shape.
    |
    | `JSON_HEX_TAG` is what prevents it. This asserts the escaping rather than the flag,
    | so the test survives somebody rewriting how the block is built.
    */
    Plan::query()->where('is_public', true)->orderBy('position')->firstOrFail()
        ->update(['name_fa' => 'پایه </script><script>x</script>']);

    cache()->forget('landing.catalogue');

    $html = $this->get($this->url.'/')->assertOk()->getContent() ?: '';

    expect($html)->not->toContain('</script><script>x')
        ->and(structuredData($html))->toHaveCount(2);
});

/* ------------------------------------------------------------------ helper -- */

/**
 * Every `application/ld+json` block on the page, decoded.
 *
 * @return list<array<string, mixed>>
 */
function structuredData(string $html): array
{
    preg_match_all(
        '#<script type="application/ld\+json">(.*?)</script>#s',
        $html,
        $matches,
    );

    $blocks = [];

    foreach ($matches[1] as $json) {
        $decoded = json_decode(trim($json), true);

        expect($decoded)->toBeArray('a structured-data block is not valid JSON');
        assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        $blocks[] = $decoded;
    }

    return $blocks;
}

/**
 * Every node of the product graph, flattened out of whichever block carries it.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @return list<array<string, mixed>>
 */
function ldGraph(array $blocks): array
{
    foreach ($blocks as $block) {
        if (! isset($block['@graph']) || ! is_array($block['@graph'])) {
            continue;
        }

        $nodes = [];

        foreach ($block['@graph'] as $node) {
            if (is_array($node)) {
                /** @var array<string, mixed> $node */
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    return [];
}

/**
 * The one node of a given `@type`, whether it stands alone or inside a graph.
 *
 * Fails the test rather than returning null: every caller here needs the node, and a
 * `null` would turn a missing block into a confusing offset error ten lines later.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @return array<string, mixed>
 */
function ldNode(array $blocks, string $type): array
{
    foreach ([...$blocks, ...ldGraph($blocks)] as $node) {
        if (($node['@type'] ?? null) === $type) {
            return $node;
        }
    }

    expect(false)->toBeTrue("no structured-data node of type {$type} on the page");

    return [];
}
