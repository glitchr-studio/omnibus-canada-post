<?php

namespace Omnibus\CanadaPost;

use Omnibus\Exception\CarrierException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Canada Post's REST web services: XML in and out, basic auth with the API username and password. */
final class Api
{
    public const LIVE = 'https://soa-gw.canadapost.ca';
    public const TEST = 'https://ct.soa-gw.canadapost.ca';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $username,
        private readonly string $password,
        public readonly string $customerNumber,
        public readonly ?string $contractId = null,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    public function base(): string
    {
        return $this->sandbox ? self::TEST : self::LIVE;
    }

    /** One call; XML back as a SimpleXMLElement without namespaces, or the raw bytes of a document. */
    public function call(string $method, string $path, ?string $body, string $contentType, ?string $accept = null, string $locale = 'en-CA'): \SimpleXMLElement|string
    {
        try {
            $response = $this->http->request($method, str_starts_with($path, 'http') ? $path : $this->base().$path, [
                'auth_basic' => [$this->username, $this->password],
                'headers' => array_filter(['Content-Type' => $contentType, 'Accept' => $accept ?? $contentType, 'Accept-language' => $locale]),
                'body' => $body,
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $type = $response->getHeaders(false)['content-type'][0] ?? '';
        } catch (HttpExceptionInterface $e) {
            throw new CarrierException('canada_post', 'Canada Post request failed: '.$e->getMessage(), null, $e);
        }
        if ($status < 400 && !str_contains($type, 'xml') && !str_starts_with(ltrim($content), '<')) {
            return $content;
        }
        $xml = self::parse($content);
        if ($status >= 400 || 'messages' === $xml->getName()) {
            $message = $xml->message[0] ?? null;
            throw new CarrierException('canada_post', (string) ($message->description ?? sprintf('HTTP %d', $status)), isset($message->code) ? (string) $message->code : null);
        }

        return $xml;
    }

    public static function parse(string $content): \SimpleXMLElement
    {
        $xml = @simplexml_load_string(preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $content));
        if (false === $xml) {
            throw new CarrierException('canada_post', 'Canada Post answered with a body that is not XML.');
        }

        return $xml;
    }

    public function contract(): bool
    {
        return null !== $this->contractId && '' !== $this->contractId;
    }
}
