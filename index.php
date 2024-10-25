<?php

use Akrez\HttpRunner\SapiEmitter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require_once './vendor/autoload.php';

class Agent
{
    protected ?ResponseInterface $response = null;

    public function __construct(protected RequestInterface $request)
    {
    }

    public function send($timeout = 60, $clientConfig = [])
    {
        $this->request = $this->request
            ->withoutHeader('Accept-Encoding')
            ->withHeader('Accept-Encoding', 'gzip, deflate');

        if ($multipartBoundary = static::getMultipartBoundary($this->request)) {
            $multipartStream = static::getMultipartStream($multipartBoundary, $this->request);
            $this->request = $this->request->withBody($multipartStream);
        }

        return $this->response = $this->sendRequest($this->request, $timeout, $clientConfig);
    }

    public function emit()
    {
        // $this->response = $this->response->withoutHeader('Transfer-Encoding');

        $this->emitResponse($this->response);
    }

    protected static function getMultipartBoundary(RequestInterface $request): ?string
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (
            strpos($contentType, 'multipart/form-data') === 0 and
            preg_match('/boundary=(.*)$/', $contentType, $matches)
        ) {
            return trim($matches[1], '"');
        }

        return null;
    }

    protected static function getMultipartStream(string $multipartBoundary, ServerRequestInterface $request)
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

        return new MultipartStream($elements, $multipartBoundary);
    }

    protected static function sendRequest($request, $timeout, $clientConfig): Response
    {
        $client = new Client(array_replace_recursive([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'read_timeout' => $timeout,
            'verify' => __DIR__.DIRECTORY_SEPARATOR.'cacert.pem',
            'allow_redirects' => false,
            'referer' => false,
            'stream' => true,
        ], $clientConfig));

        try {
            return $client->send($request);
        } catch (ClientException $e) {
            return $e->getResponse();
        } catch (ServerException $e) {
            return $e->getResponse();
        } catch (Throwable $e) {
            return new Response(501, [], json_encode((array) $e), 1.1, 'Internal Server Throwable Error');
        } catch (Exception $e) {
            return new Response(502, [], json_encode((array) $e), 1.1, 'Internal Server Exception Error');
        }
    }

    protected static function emitResponse($response)
    {
        (new SapiEmitter(1024))->emit($response);
    }
}

function getNewUrl($globalServer)
{
    $globalServer = $globalServer + [
        'PATH_INFO' => null,
        'SCRIPT_NAME' => null,
        'REQUEST_URI' => null,
    ];

    if ($globalServer['PATH_INFO']) {
        $slashedTargetUrl = $globalServer['PATH_INFO'];
    } elseif ($globalServer['SCRIPT_NAME'] and $globalServer['REQUEST_URI']) {
        $basePath = str_replace(basename($globalServer['SCRIPT_NAME']), '', $globalServer['SCRIPT_NAME']);
        $slashedTargetUrl = substr($globalServer['REQUEST_URI'], strlen($basePath));
    } else {
        $slashedTargetUrl = null;
    }

    if ($slashedTargetUrl) {
        $slashedTargetUrl = ltrim($slashedTargetUrl, " \n\r\t\v\0/");
    } else {
        return null;
    }

    $parts = explode('/', $slashedTargetUrl, 2);

    $result = [];
    if (in_array($parts[0], ['https', 'debughttps', 'http', 'debughttp'])) {
        if (empty($parts[1])) {
            return null;
        }
        $result['schema'] = str_replace('debug', '', $parts[0]);
        $result['url'] = $parts[1];
        $result['debug'] = (strpos($parts[0], 'debug') === 0);
    } else {
        if (empty($parts[0])) {
            return null;
        }
        $result['schema'] = 'https';
        $result['url'] = implode('/', $parts);
        $result['debug'] = false;
    }

    return $result;
}

$parts = getNewUrl($_SERVER);
if (! $parts) {
    exit('Hard');
}
$request = ServerRequest::fromGlobals()->withUri(new Uri($parts['schema'].'://'.$parts['url']));
if ($parts['debug']) {
    exit(Message::toString($request));
}
$agent = new Agent($request);
$res = $agent->send();
$agent->emit();
