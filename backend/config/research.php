<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Research Transport (kodizm MCP)
    |--------------------------------------------------------------------------
    |
    | The monitor-setup agent is given exactly two research tools, and both of
    | them travel through the kodizm MCP server rather than reaching a host
    | directly. The whole surface is DORMANT until `token` is filled: with no
    | token the tools refuse in a sentence the model can act on and send
    | nothing at all, so a deployment without the credential degrades to no
    | research instead of to an error.
    |
    | A bare JSON-RPC `tools/call` POST is the entire protocol here: measured
    | against the live endpoint, it answers HTTP 200 with no `initialize` call
    | and no session header (see the plan's research/verification-log.md entry
    | 4). Nothing below may grow a handshake on a guess.
    |
    */

    'kodizm' => [

        'url' => env('KODIZM_MCP_URL', 'https://mcp.kodizm.com/'),

        'token' => env('KODIZM_MCP_TOKEN'),

        /*
        | Short on purpose. An operator is holding an open analyze request while
        | the model waits on this hop, and a slow research call is worse for
        | them than no research at all.
        */
        'timeout_seconds' => (int) env('KODIZM_MCP_TIMEOUT_SECONDS', 12),

        /*
        | The remote tool names. They belong to the kodizm server, not to this
        | app, so a rename there is a config change here rather than a code
        | change. An unknown name comes back as a tool error, which the client
        | reports as no result and the tools turn into a refusal string.
        */
        'tools' => [
            'search' => 'web-search',
            'fetch' => 'web-fetch',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bounds On One Tool Call
    |--------------------------------------------------------------------------
    |
    | Everything a tool returns is attacker-influenceable text that lands in the
    | model's context, so every axis is capped: how long a query may be, how
    | many results come back, how much of each result, and how much of a fetched
    | page. These are ceilings, not targets.
    |
    */

    'limits' => [

        'query_chars' => 200,

        'results' => 5,

        'result_field_chars' => 300,

        'page_chars' => 6000,
    ],

];
