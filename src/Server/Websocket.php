<?php

namespace Hangjw\LaravelS\Server;

use Hangjw\LaravelS\Illuminate\Laravel;
use Hangjw\LaravelS\Request\Websocket as Request;
use Hangjw\LaravelS\Response\Websocket\Response;



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

    public function onWorkerStart(\swoole_websocket_server $server, $workerId)
    {
        $this->setProcessTitle(sprintf('%s laravelswebsocket: worker process %d', $this->conf['process_prefix'], $workerId));

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }

        clearstatcache();
        // To implement gracefully reload
        // Delay to create Laravel
        // Delay to include Laravel's autoload.php

        $this->laravel = new Laravel($this->laravelConf);
        $this->laravel->prepareLaravel();
        $this->laravel->singleton('swoole', $this->swoole);
        $this->laravel->singleton('webSocket', new Response($server));
        $this->laravel->singleton('swooleServer', $server);
    }

    public function response($type, $fd, $extra=[])
    {
        try {
            app('webSocket')->setFd($fd);
            $laravelRequest = (new Request($type, $extra))->toIlluminateRequest();
            $this->laravel->handleDynamic($laravelRequest);
        } catch (\Exception $e) {
            file_put_contents(storage_path('logs/swoole-socket-' . date('Y-m-d') . '.log'), $type . var_export($e, true), FILE_APPEND);
        }
    }

    public function onOpen($ws, $request)
    {
        $this->response(Request::TYPE['open'], $request->fd, $request);
    }

    public function onMessage($ws, $request) {
        $this->response(Request::TYPE['message'], $request->fd, $request);
    }

    public function onClose($ws, $fd) {
        $this->response(Request::TYPE['close'], $fd);
    }


    public function bind()
    {
        $this->swoole->on('Open', [$this, 'onOpen']);
        $this->swoole->on('Message', [$this, 'onMessage']);
        $this->swoole->on('Close', [$this, 'onClose']);
        $this->swoole->on('ManagerStart', [$this, 'onManagerStart']);
        $this->swoole->on('WorkerStart', [$this, 'onWorkerStart']);
    }

    public function onManagerStart(\swoole_http_server $server)
    {
        $this->setProcessTitle(sprintf('%s laravelwebsocket: manager process', $this->conf['process_prefix']));
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
