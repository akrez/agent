<?php

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;

require_once './vendor/autoload.php';

function extractUri()
{
    $basePath = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
    $slashedTargetUrl = substr($_SERVER['REQUEST_URI'], strlen($basePath));
    $parts = explode('/', $slashedTargetUrl, 2) + array_fill(0, 2, null);

    if (
        in_array($parts[0], ['http', 'https']) and
        $parts[1] and
        $url = filter_var($parts[0].'://'.$parts[1], FILTER_VALIDATE_URL)
    ) {
        return $url;
    }

    return null;
}

$newUri = extractUri();

$request = ServerRequest::fromGlobals()->withUri(new Uri($newUri));
