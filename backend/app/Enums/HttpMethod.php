<?php

namespace App\Enums;

/**
 * HTTP verb used when issuing a monitor check request.
 *
 * Only verbs that the Flutter create form exposes are modelled here.
 */
enum HttpMethod: string
{
    case Get = 'get';
    case Post = 'post';
    case Head = 'head';
}
