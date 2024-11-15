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
    public function __construct(protected RequestInterface $request, protected $debug = false)
    {
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
        $this->request = $this->beforeSend($this->request);

        if ($multipartBoundary = $this->getMultipartBoundary($this->request)) {
            $multipartStream = $this->getMultipartStream($multipartBoundary, $this->request);
            $this->request = $this->request->withBody($multipartStream);
        }

        return $this->sendRequest($this->request, $timeout, $clientConfig);
    }

    protected function beforeSend(RequestInterface $request): RequestInterface
    {
        return $request
            ->withoutHeader('Accept-Encoding')
            ->withHeader('Accept-Encoding', 'gzip, deflate');
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

    protected function sendRequest($request, $timeout, $clientConfig): Response
    {
        $client = new Client(array_replace_recursive([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'read_timeout' => $timeout,
            'verify' => __DIR__.DIRECTORY_SEPARATOR.'cacert.pem',
            'allow_redirects' => false,
            'referer' => false,
            'sink' => fopen('php://output', 'w'),
            'on_headers' => function (ResponseInterface $response) {
                (new SapiEmitter())->emit($response, true);
            },
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

        $url = ltrim($globalServer['PATH_INFO'], " \n\r\t\v\0/");
        if (empty($url)) {
            return null;
        }

        $parts = explode('/', $url, 2) + [0 => null, 1 => null];

        $result = [
            'url' => $url,
            'schema' => (isset($globalServer['HTTPS']) ? 'https' : 'http'),
            'method' => $globalServer['REQUEST_METHOD'],
            'debug' => false,
            'protocol_version' => (str_replace('HTTP/', '', $globalServer['SERVER_PROTOCOL'])) * 10,
        ];

        if (static::isStringContain($parts[0], '.')) {
            return static::buildUsingParameters($serverRequest, $parts[0], $result);
        }

        return static::buildUsingParameters($serverRequest, $parts[1], $result, $parts[0]);
    }

    public static function buildUsingParameters(RequestInterface $serverRequest, ?string $url, array $default, string $configString = '')
    {
        if (empty($url)) {
            return null;
        }

        $firstLineParts = explode('_', $configString);

        return static::build(
            $serverRequest,
            static::findInArray($firstLineParts, ['get', 'post', 'head', 'put', 'delete', 'options', 'trace', 'connect', 'patch'], $default['method']),
            static::findInArray($firstLineParts, ['https', 'http'], $default['schema']),
            $url,
            static::findInArray([10, 11, 20, 30], $firstLineParts, $default['protocol_version']) / 10.0,
            static::findInArray($firstLineParts, ['debug'], $default['debug']),
        );
    }

    public static function build(RequestInterface $serverRequest, $method, $schema, $url, $protocolVersion, $debug)
    {
        $request = $serverRequest
            ->withMethod($method)
            ->withUri(new Uri($schema.'://'.$url))
            ->withProtocolVersion(strval($protocolVersion));

        return new Agent($request, $debug);
    }

    protected static function isStringContain(string $haystack, string $needle, int $offset = 0): bool
    {
        return false !== strpos($haystack, $needle, $offset);
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
