<?php

namespace BraintreeHttp;

class HttpException extends IOException
{
    public $statusCode;

    public $headers;

    /**
     * HttpException constructor.
     * @param $message
     * @param $statusCode
     * @param $headers
     */
    public function __construct($message, $statusCode, $headers)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }
}
