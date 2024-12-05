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
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require_once './vendor/autoload.php';

class Agent
{
    protected RequestInterface $request;

    protected bool $debug;

    public function __construct(RequestInterface $serverRequest, ?string $url, ?string $method = null, ?string $schema = null, ?string $protocolVersion = null, bool $debug = false)
    {
        $defaultUri = $serverRequest->getUri();

        $newSchema = ($schema ?: $defaultUri->getScheme());
        $newUri = ($url ? new Uri($url) : $defaultUri)->withScheme($newSchema);

        $serverRequest = $serverRequest->withUri($newUri);

        if ($method) {
            $serverRequest = $serverRequest->withMethod($method);
        }

        if ($protocolVersion) {
            $serverRequest = $serverRequest->withProtocolVersion(strval($protocolVersion));
        }

        if ($multipartBoundary = $this->getMultipartBoundary($serverRequest)) {
            $multipartStream = $this->getMultipartStream($multipartBoundary, $serverRequest);
            $serverRequest = $serverRequest->withBody($multipartStream);
        }

        $this->request = $this->prepareRequest($serverRequest);
        $this->debug = boolval($debug);
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function getDebug()
    {
        return $this->debug;
    }

    public function send($timeout = 60, $clientConfig = [])
    {
        return $this->sendRequest($this->request, $timeout, $clientConfig);
    }

    protected function prepareRequest(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    protected function getMultipartBoundary(RequestInterface $request): ?string
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

    protected function getMultipartStream(string $multipartBoundary, ServerRequestInterface $request)
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

    protected function sendRequest(RequestInterface $request, $timeout, $clientConfig): Response
    {
        ini_set('output_buffering', 'Off');
        ini_set('output_handler', '');
        ini_set('zlib.output_compression', 0);

        $client = new Client(array_replace_recursive([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'read_timeout' => $timeout,
            'verify' => false,
            'allow_redirects' => false,
            'referer' => false,
            'sink' => fopen('php://output', 'w'),
            'on_headers' => function (ResponseInterface $response) {
                (new SapiEmitter())->emit($response, true);
            },
            RequestOptions::DECODE_CONTENT => false,
        ], $clientConfig));

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
}

class AgentFactory
{
    public static function buildUsingGlobalServer(RequestInterface $serverRequest, array $globalServer)
    {
        $globalServer = $globalServer + [
            'PATH_INFO' => null,
            'HTTPS' => null,
            'REQUEST_METHOD' => null,
            'SERVER_PROTOCOL' => null,
        ];
        $globalServer = array_map('strval', $globalServer);

        $url = ltrim($globalServer['PATH_INFO'], " \n\r\t\v\0/");
        if (empty($url)) {
            return null;
        }
        $url = $url.(empty($globalServer['QUERY_STRING']) ? '' : '?'.$globalServer['QUERY_STRING']);

        $parts = explode('/', $url, 2) + [0 => null, 1 => null];

        $default = [
            'url' => $url,
            'schema' => (isset($globalServer['HTTPS']) ? 'https' : 'http'),
            'method' => $globalServer['REQUEST_METHOD'],
            'debug' => false,
            'protocol_version' => (str_replace('HTTP/', '', $globalServer['SERVER_PROTOCOL'])) * 10,
        ];

        if (false === strpos($parts[0], '.')) {
            $configs = explode('_', $parts[0]);
            $url = $parts[1];
        } else {
            $configs = [];
            $url = $parts[0];
        }

        if (empty($url)) {
            return null;
        }

        return new Agent(
            $serverRequest,
            $url,
            static::findInArray($configs, ['get', 'post', 'head', 'put', 'delete', 'options', 'trace', 'connect', 'patch'], $default['method']),
            static::findInArray($configs, ['https', 'http'], $default['schema']),
            static::findInArray([10, 11, 20, 30], $configs, $default['protocol_version']) / 10.0,
            static::findInArray($configs, ['debug'], $default['debug'])
        );
    }

    protected static function findInArray(array $needles, array $haystack, string $default = null): ?string
    {
        foreach ($needles as $needle) {
            if (in_array(strtolower($needle), $haystack)) {
                return $needle;
            }
        }

        return $default;
    }
}

$agent = AgentFactory::buildUsingGlobalServer(ServerRequest::fromGlobals(), $_SERVER);
if ($agent) {
    if ($agent->getDebug()) {
        exit(Message::toString($agent->getRequest()));
    }
    $agent->send(300);
} else {
    exit('Hard');
}
