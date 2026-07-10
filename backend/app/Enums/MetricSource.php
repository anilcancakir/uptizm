<?php

namespace App\Enums;

/**
 * Extraction strategy used to pull a metric value out of a check response.
 *
 * The paired `extraction_path` column holds the concrete selector
 * (JSONPath expression, regex pattern, XPath query, or header name).
 */
enum MetricSource: string
{
    case JsonPath = 'json_path';
    case Regex = 'regex';
    case Xpath = 'xpath';
    case Header = 'header';
    case HttpStatus = 'http_status';
}
