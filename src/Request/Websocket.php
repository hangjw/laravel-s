<?php

namespace Hangjw\LaravelS\Request;

use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

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

    private $key = 'web_socket_fd';

    /**
     * @return IlluminateRequest
     */
    public function toIlluminateRequest($fd)
    {
        $key = md5($this->key . $fd);
        $dir = storage_path() . '/websocket';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $file = storage_path() . '/websocket/' . $key;
        if ($this->socketType == self::TYPE['open']) {
            $_GET = isset($this->request->get) ? $this->request->get : [];
            $_POST = isset($this->request->post) ? $this->request->post : [];
            $_COOKIE = isset($this->request->cookie) ? $this->request->cookie : [];
            $_SERVER = isset($this->request->server) ? $this->request->server : [];
            $headers = isset($this->request->header) ? $this->request->header : [];
            $_FILES = isset($this->request->files) ? $this->request->files : [];
            $_ENV = [];
            $_REQUEST = [];
            foreach ($headers as $headerKey => $value) {
                $_SERVER['http_' . str_replace('-', '_', $headerKey)] = $value;
            }
            $_SERVER = array_change_key_case($_SERVER, CASE_UPPER);
            $pathInfo = $_SERVER['REQUEST_URI'];
            $_SERVER['REQUEST_URI'] .= '/open';
            $_SERVER['PATH_INFO']   .= '/open';
            $requests = ['C' => $_COOKIE, 'G' => $_GET, 'P' => $_POST];
            $requestOrder = ini_get('request_order') ?: ini_get('variables_order');
            $requestOrder = preg_replace('#[^CGP]#', '', strtoupper($requestOrder)) ?: 'GP';
            foreach (str_split($requestOrder) as $order) {
                $_REQUEST = array_merge($_REQUEST, $requests[$order]);
            }
            $request = IlluminateRequest::capture();
//            Cache::forever($key, $pathInfo);
            file_put_contents($file, $pathInfo);
        } elseif ($this->socketType == self::TYPE['message']) {
            $input = $this->request->data;
            $data = json_decode($input, true);
            $_GET = $_POST = $_COOKIE = $_SERVER = $headers = $_FILES = $_ENV = $_REQUEST = [];
            $_SERVER = [
                'REQUEST_URI' => $data['path_info'],
                'PATH_INFO' => $data['path_info'],
            ];
            $request = IlluminateRequest::capture();
            $request->data = $data['data'];
        } elseif ($this->socketType == self::TYPE['close']) {
            $_GET = $_POST = $_COOKIE = $_SERVER = $headers = $_FILES = $_ENV = $_REQUEST = [];
//            $path = Cache::get($key);
            $path = file_get_contents($file);
            unlink($file);
            $_SERVER = [
                'REQUEST_URI' => $path . '/close',
                'PATH_INFO' => $path . '/close',
            ];
            $request = IlluminateRequest::capture();
        }
        $request->fd = $fd;
        return $request;
    }

}
