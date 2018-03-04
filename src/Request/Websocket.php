<?php

namespace Hangjw\LaravelS\Request;

use Illuminate\Http\Request as IlluminateRequest;

class Websocket
{
    const TYPE = [
        'close' => 3,
        'open'  => 1,
        'message' => 2,
    ];
    protected $request;
    protected $socketType;

    public function __construct($socketType, $request)
    {
        $this->request = $request;
        $this->socketType = $socketType;
    }

    /**
     * @return IlluminateRequest
     */
    public function toIlluminateRequest()
    {
        file_put_contents(__DIR__ . '/2', 1, FILE_APPEND);

        $_GET = $_POST = $_COOKIE = $_SERVER = $headers = $_FILES = $_ENV = $_REQUEST = [];
        $_SERVER = [
            'REQUEST_URI' => '/test/index',
            'PATH_INFO' => '/test/index',
        ];

        $request = IlluminateRequest::capture();
        file_put_contents(__DIR__ . '/2', var_export($request, true), FILE_APPEND);

        $reflection = new \ReflectionObject($request);
        $content = $reflection->getProperty('content');
        $content->setAccessible(true);
        $content->setValue($request, $this->swooleRequest->rawContent());


        return $request;
    }

}
