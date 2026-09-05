{{--
    The sitemap. Three URLs, which is the entire public surface of this application.

    ## Why the first line is spelled so strangely

    `<?xml` cannot appear literally in a Blade template. Blade compiles with PHP's own
    tokenizer, which reads that sequence as an opening PHP tag and hands everything after
    it to the parser as code — the template then fails with `unexpected identifier
    "version"`, and nothing in the message mentions XML. Splitting the `<` from the `?`
    means the sequence never exists in the source, and the two halves are concatenated
    into the output at render time.

    ## Why a view rather than a string built in the route

    `{{ }}` escapes, and a URL is the one thing here that will contain an `&` the day one
    of these routes grows a query string. An unescaped ampersand is an XML parse error,
    and a malformed sitemap is rejected whole rather than partially.
--}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
        <changefreq>{{ $url['changefreq'] }}</changefreq>
        <priority>{{ $url['priority'] }}</priority>
    </url>
@endforeach
</urlset>
