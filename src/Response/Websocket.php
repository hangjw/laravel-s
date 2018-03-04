<?php

namespace Hangjw\LaravelS\Server;

use Hangjw\LaravelS\Illuminate\Laravel;
use Hangjw\LaravelS\Swoole\DynamicResponse;
use Hangjw\LaravelS\Swoole\Request;
use Hangjw\LaravelS\Swoole\Server;
use Hangjw\LaravelS\Swoole\StaticResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;


/**
 * Swoole Request => Laravel Request
 * Laravel Request => Laravel handle => Laravel Response
 * Laravel Response => Swoole Response
 */
class Websocket extends Server
{
    protected static $s;

    protected $laravelConf;

    /**
     * @var Laravel $laravel
     */
    protected $laravel;

    protected function __construct(array $svrConf = [], array $laravelConf)
    {
        $this->conf = $svrConf;
        $ip = isset($svrConf['listen_ip']) ? $svrConf['listen_ip'] : '0.0.0.0';
        $port = isset($svrConf['listen_port']) ? $svrConf['listen_port'] : 8841;
        $settings = isset($svrConf['swoole']) ? $svrConf['swoole'] : [];

        if (isset($settings['ssl_cert_file'], $settings['ssl_key_file'])) {
            $this->swoole = new \swoole_websocket_server($ip, $port, \SWOOLE_PROCESS, \SWOOLE_SOCK_TCP | \SWOOLE_SSL);
        } else {
            $this->swoole = new \swoole_websocket_server($ip, $port, \SWOOLE_PROCESS);
        }

        $default = [
            'reload_async'      => true,
            'max_wait_time'     => 60,
            'enable_reuse_port' => true,
        ];

        $this->swoole->set($settings + $default);
        $this->laravelConf = $laravelConf;
    }



    public function onOpen($ws, $request)
    {
        try {
            $laravelRequest = (new Request($request))->toIlluminateRequest();
            $this->laravel->fireEvent('laravels.received_request', [$laravelRequest]);
            $success = $this->handleStaticResource($laravelRequest, $response);
            if ($success === false) {
                $this->handleDynamicResource($laravelRequest, $response);
            }
        } catch (\Exception $e) {
            echo sprintf('[%s][ERROR][LaravelS]onRequest: %s:%s, [%d]%s%s%s', date('Y-m-d H:i:s'), $e->getFile(), $e->getLine(), $e->getCode(), $e->getMessage(), PHP_EOL, $e->getTraceAsString()), PHP_EOL;
            try {
                $response->status(500);
                $response->end('Oops! An unexpected error occurred, please take a look the Swoole log.');
            } catch (\Exception $e) {
                // Catch: zm_deactivate_swoole: Fatal error: Uncaught exception 'ErrorException' with message 'swoole_http_response::status(): http client#2 is not exist.
            }
        }

        $arr = [$request, $ws];
        $ws->push($request->fd, json_encode($arr) . "\n");
    }

    public function onMessage($ws, $request) {
        $arr = [$request, $ws];
        $ws->push($request->fd, json_encode($arr) . "\n");
    }

    public function onClose($ws, $fd) {
        echo "client-{$fd} is closed\n";
    }


    public function bind()
    {
        $this->swoole->on('Open', [$this, 'onOpen']);
        $this->swoole->on('Message', [$this, 'onMessage']);
        $this->swoole->on('Close', [$this, 'onClose']);
    }

    private function __clone()
    {

    }

    private function __sleep()
    {
        return [];
    }

    public function __wakeup()
    {
        self::$s = $this;
    }

    public function __destruct()
    {

    }

    public static function getInstance(array $svrConf = [], array $laravelConf = [])
    {
        if (self::$s === null) {
            self::$s = new static($svrConf, $laravelConf);
        }
        return self::$s;
    }


}
