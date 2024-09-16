<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Laminas\HttpHandlerRunner\Emitter\SapiStreamEmitter;
use Psr\Http\Message\ServerRequestInterface;

require_once './vendor/autoload.php';

function requestFromGlobals()
{
    return ServerRequest::fromGlobals();
}

function getNewUri()
{
    $slashedTargetUrl = $_SERVER['PATH_INFO'];

    $slashedTargetUrl = ltrim($slashedTargetUrl, " \n\r\t\v\0/");
    $parts = explode('/', $slashedTargetUrl, 2) + array_fill(0, 2, null);

    if (
        in_array($parts[0], ['http', 'https']) and
        $parts[1] and
        $url = filter_var($parts[0] . '://' . $parts[1], FILTER_VALIDATE_URL)
    ) {
        return $url;
    }

    return null;
}

function getBoundary(ServerRequestInterface $serverRequest): ?string
{
    $contentType = $serverRequest->getHeaderLine('Content-Type');

    if (
        strpos($contentType, 'multipart/form-data') === 0 and
        $serverRequest->getMethod() === 'POST' and
        preg_match('/boundary=(.*)$/', $contentType, $matches)
    ) {
        return trim($matches[1], '"');
    }

    return null;
}

function setMultipart(string $boundaryName, ServerRequestInterface $request)
{
    $elements = [];

    foreach ($request->getParsedBody() as $key => $value) {
        $elements[] = [
            'name' => $key,
            'contents' => $value,
        ];
    }

    foreach ($request->getUploadedFiles() as $key => $value) {
        if (empty($value->getError())) {
            $elements[] = [
                'name' => $key,
                'filename' => $value->getClientFilename(),
                'contents' => $value->getStream(),
            ];
        }
    }

    $body = new MultipartStream($elements, $boundaryName);

    return $request->withBody($body);
}

function send($request, $clientConfig = [])
{
    $timeout = ini_get('max_execution_time') ?? 60;

    $defaultConfig = [
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'read_timeout' => $timeout,
        'verify' => false,
        'allow_redirects' => false,
        'referer' => false,
    ];

    $client = new Client(array_replace_recursive(
        $defaultConfig,
        $clientConfig
    ));

    try {
        return $client->send($request);
    } catch (ClientException $e) {
        return $e->getResponse();
    } catch (ServerException $e) {
        return $e->getResponse();
    } catch (Throwable $e) {
        return new Response(500, [], json_encode((array) $e), 1.1, 'Internal Server Throwable Error');
    } catch (Exception $e) {
        return new Response(500, [], json_encode((array) $e), 1.1, 'Internal Server Exception Error');
    }
}

$newUri = getNewUri();
if (! $newUri) {
    die('Hard');
}

$request = ServerRequest::fromGlobals()->withUri(new Uri($newUri));

// $request = $request
//     ->withoutHeader('Accept-Encoding')
//     ->withHeader('Accept-Encoding', 'gzip, deflate');

if ($boundaryName = getBoundary($request)) {
    $request = setMultipart($boundaryName, $request);
}

$response = send($request);

// $response = $response->withoutHeader('Transfer-Encoding');

if (!$response->hasHeader('Content-Disposition') && !$response->hasHeader('Content-Range')) {
    (new SapiEmitter())->emit($response);
} else {
    (new SapiStreamEmitter())->emit($response);
}
