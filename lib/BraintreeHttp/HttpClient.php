<?php

namespace BraintreeHttp;

/**
 * Class HttpClient
 * @package BraintreeHttp
 *
 * Client used to make HTTP requests.
 */
class HttpClient
{
    /**
     * @var Environment
     */
    public $environment;

    /**
     * @var Injector[]
     */
    public $injectors = array();

    /**
     * @var Encoder
     */
    public $encoder;

    /**
     * @var array
     */
    private static $headers = array();

    /**
     * @var Curl
     */
    private $curl = null;

    /**
     * HttpClient constructor. Pass the environment you wish to make calls to.
     *
     * @param $environment Environment
     * @see Environment
     */
    function __construct(Environment $environment)
    {
        $this->environment = $environment;
        $this->encoder = new Encoder();
    }

    /**
     * Injectors are blocks that can be used for executing arbitrary pre-flight logic, such as modifying a request or logging data.
     * Executed in first-in first-out order.
     *
     * @param Injector $inj
     */
    public function addInjector(Injector $inj)
    {
        $this->injectors[] = $inj;
    }

    /**
     * The method that takes an HTTP request, serializes the request, makes a call to given environment, and deserialize response
     *
     * @param $httpRequest HttpRequest
     * @return HttpResponse
     * @throws HttpException
     * @throws IOException
     * @throws \Exception
     */
    public function execute(HttpRequest $httpRequest)
    {
        $requestCpy = clone $httpRequest;

        if ($this->curl === null) {
            $curl = new Curl();
        } else {
            $curl = $this->curl;
        }

        foreach ($this->injectors as $inj) {
            $inj->inject($requestCpy);
        }

        if (!array_key_exists("User-Agent", $requestCpy->headers)) {
            $requestCpy->headers["User-Agent"] = $this->userAgent();
        }

        $url = $this->environment->baseUrl() . $requestCpy->path;

        $body = "";
        if (!is_null($requestCpy->body)) {
            $body = $this->encoder->serializeRequest($requestCpy);
        }

        $curl->setOpt(CURLOPT_URL, $url);
        $curl->setOpt(CURLOPT_CUSTOMREQUEST, $requestCpy->verb);
        $curl->setOpt(CURLOPT_HTTPHEADER, $this->serializeHeaders($requestCpy->headers));
        $curl->setOpt(CURLOPT_RETURNTRANSFER, 1);
        $curl->setOpt(CURLOPT_HEADER, 0);

        if (!is_null($requestCpy->body)) {
            $curl->setOpt(CURLOPT_POSTFIELDS, $body);
        }

        if (strpos($this->environment->baseUrl(), "https://") === 0) {
            $curl->setOpt(CURLOPT_SSL_VERIFYPEER, true);
            $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        }

        if ($caCertPath = $this->getCACertFilePath()) {
            $curl->setOpt(CURLOPT_CAINFO, $caCertPath);
        }

        $response = $this->parseResponse($curl);
        $curl->close();

        return $response;
    }

    /**
     * Returns default user-agent
     *
     * @return string
     */
    public function userAgent()
    {
        return "BraintreeHttp-PHP HTTP/1.1";
    }

    /**
     * Return the filepath to your custom CA Cert if needed.
     * @return string
     */
    protected function getCACertFilePath()
    {
        return null;
    }

    protected function setCurl(Curl $curl)
    {
        $this->curl = $curl;
    }

    protected function setEncoder(Encoder $encoder)
    {
        $this->encoder = $encoder;
    }

    private function serializeHeaders($headers)
    {
        $headerArray = array();
        if ($headers) {
            foreach ($headers as $key => $val) {
                $headerArray[] = $key . ": " . $val;
            }
        }

        return $headerArray;
    }
    
    public function parseHeader($curl, $header)
    {
        $len = strlen($header);
    
        $k = "";
        $v = "";
    
        $this->deserializeHeader($header, $k, $v);
        self::$headers[$k] = $v;
    
        return $len;
    }

    /**
     * @param $curl
     * @return HttpResponse
     * @throws HttpException
     * @throws \Exception
     */
    private function parseResponse(Curl $curl)
    {
        self::$headers = array();
        $curl->setOpt(CURLOPT_HEADERFUNCTION, array($this, 'parseHeader'));

        $responseData = $curl->exec();
        $statusCode = $curl->getInfo(CURLINFO_HTTP_CODE);
        $errorCode = $curl->errNo();
        $error = $curl->error();

        if ($errorCode > 0) {
            throw new IOException($error, $errorCode);
        }

        $body = $responseData;

        if ($statusCode >= 200 && $statusCode < 300) {
            $responseBody = NULL;

            if (!empty($body)) {
                $responseBody = $this->encoder->deserializeResponse($body, self::$headers);
            }

            return new HttpResponse(
                $errorCode === 0 ? $statusCode : $errorCode,
                $responseBody,
                self::$headers
            );
        } else {
            throw new HttpException($body, $statusCode, self::$headers);
        }
    }

    private function deserializeHeader($header, &$key, &$value)
    {
        if (strlen($header) > 0) {
            if (empty($header) || strpos($header, ':') === false) {
                return NULL;
            }

            list($k, $v) = explode(":", $header);
            $key = trim($k);
            $value = trim($v);
        }
    }
}
