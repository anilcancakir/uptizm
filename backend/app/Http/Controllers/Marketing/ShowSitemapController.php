<?php

namespace App\Http\Controllers\Marketing;

use App\Services\Services\SitemapBuilder;
use Illuminate\Http\Response;

/**
 * Serves the sitemap index and its two segments.
 *
 * Three actions on one controller rather than three single-action classes: they
 * are three renderings of one document set, they share the content type, and
 * splitting them would put the same `->header()` in three files.
 *
 * ## No ping, no Indexing API, deliberately
 *
 * Google retired the sitemap ping endpoint and states that calling it "will also
 * not do anything useful", and the Indexing API is scoped to `JobPosting` and
 * `BroadcastEvent`, neither of which this site publishes. So there is nothing
 * here that announces a change to anybody: the sitemap is referenced from
 * `public/robots.txt` and discovered from there. `SitemapTest` greps the tree for
 * either mechanism and fails if one appears.
 *
 * The content type is `application/xml` with no charset parameter, because the
 * XML declaration inside each document already states its encoding.
 */
class ShowSitemapController
{
    /**
     * The one content type all three documents are served under.
     */
    public const string CONTENT_TYPE = 'application/xml';

    public function __construct(
        protected SitemapBuilder $builder,
    ) {}

    /**
     * The index: two segments, and no `<url>` element of its own.
     */
    public function index(): Response
    {
        return $this->xml($this->builder->index());
    }

    /**
     * The landing page and the long-form documents.
     */
    public function marketing(): Response
    {
        return $this->xml($this->builder->marketing());
    }

    /**
     * The catalog hub plus every published service.
     */
    public function services(): Response
    {
        return $this->xml($this->builder->services());
    }

    protected function xml(string $document): Response
    {
        return response($document, 200, [
            'Content-Type' => self::CONTENT_TYPE,
        ]);
    }
}
