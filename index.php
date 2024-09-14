<?php

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\ServerRequest;

require './vendor/autoload.php';

$req = ServerRequest::fromGlobals();


dd(
    $req->getUploadedFiles(),
    substr($_SERVER['REQUEST_URI'], 0, strlen(dirname($_SERVER['PHP_SELF']))),
    __DIR__,
    $_SERVER['PHP_SELF'],
    dirname($_SERVER['PHP_SELF']),
    $req->getUri(),
    $_SERVER
);
