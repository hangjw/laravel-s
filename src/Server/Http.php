<?php

namespace Hangjw\LaravelS\Server;

use Hangjw\LaravelS\Illuminate\Laravel;
use Hangjw\LaravelS\Response\Http\DynamicResponse;
use Hangjw\LaravelS\Response\Http\StaticResponse;
use Hangjw\LaravelS\Request\Http as Request;
use Illuminate\Http\Request as IlluminateRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;


/**
 * Swoole Request => Laravel Request
 * Laravel Request => Laravel handle => Laravel Response
 * Laravel Response => Swoole Response
 */
class Http extends Server
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
        $settings['enable_static_handler'] = !empty($svrConf['handle_static']);

        if (isset($settings['ssl_cert_file'], $settings['ssl_key_file'])) {
            $this->swoole = new \swoole_http_server($ip, $port, \SWOOLE_PROCESS, \SWOOLE_SOCK_TCP | \SWOOLE_SSL);
        } else {
            $this->swoole = new \swoole_http_server($ip, $port, \SWOOLE_PROCESS);
        }

        $default = [
            'reload_async'      => true,
            'max_wait_time'     => 60,
            'enable_reuse_port' => true,
        ];

        $this->swoole->set($settings + $default);
        $this->laravelConf = $laravelConf;
    }


    public function bind()
    {
        $this->swoole->on('Start', [$this, 'onStart']);
        $this->swoole->on('Shutdown', [$this, 'onShutdown']);
        $this->swoole->on('ManagerStart', [$this, 'onManagerStart']);
        $this->swoole->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->swoole->on('WorkerStop', [$this, 'onWorkerStop']);
        if (version_compare(\swoole_version(), '1.9.17', '>=')) {
            $this->swoole->on('WorkerExit', [$this, 'onWorkerExit']);
        }
        $this->swoole->on('WorkerError', [$this, 'onWorkerError']);
        $this->swoole->on('Request', [$this, 'onRequest']);
    }

    public function onWorkerStart(\swoole_http_server $server, $workerId)
    {
        $this->setProcessTitle(sprintf('%s laravels: worker process %d', $this->conf['process_prefix'], $workerId));

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
    }

    public function onRequest(\swoole_http_request $request, \swoole_http_response $response)
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
    }

    protected function handleStaticResource(IlluminateRequest $laravelRequest, \swoole_http_response $swooleResponse)
    {
        // For Swoole < 1.9.17
        if (!empty($this->conf['handle_static'])) {
            $laravelResponse = $this->laravel->handleStatic($laravelRequest);
            if ($laravelResponse !== false) {
                $laravelResponse->headers->set('Server', $this->conf['server'], true);
                $this->laravel->fireEvent('laravels.generated_response', [$laravelRequest, $laravelResponse]);
                (new StaticResponse($swooleResponse, $laravelResponse))->send($this->conf['enable_gzip']);
                return true;
            }
        }
        return false;
    }

    protected function handleDynamicResource(IlluminateRequest $laravelRequest, \swoole_http_response $swooleResponse)
    {
        $laravelResponse = $this->laravel->handleDynamic($laravelRequest);
        $laravelResponse->headers->set('Server', $this->conf['server'], true);
        $this->laravel->fireEvent('laravels.generated_response', [$laravelRequest, $laravelResponse]);
        $this->laravel->cleanRequest($laravelRequest);
        if ($laravelResponse instanceof BinaryFileResponse) {
            (new StaticResponse($swooleResponse, $laravelResponse))->send($this->conf['enable_gzip']);
        } else {
            (new DynamicResponse($swooleResponse, $laravelResponse))->send($this->conf['enable_gzip']);
        }
        return true;
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

    public function onStart(\swoole_http_server $server)
    {
        foreach (spl_autoload_functions() as $function) {
            spl_autoload_unregister($function);
        }

        $this->setProcessTitle(sprintf('%s laravels: master process', $this->conf['process_prefix']));

        if (version_compare(\swoole_version(), '1.9.5', '<')) {
            file_put_contents($this->conf['swoole']['pid_file'], $server->master_pid);
        }
    }

    public function onShutdown(\swoole_http_server $server)
    {

    }

    public function onManagerStart(\swoole_http_server $server)
    {
        $this->setProcessTitle(sprintf('%s laravels: manager process', $this->conf['process_prefix']));
    }

    public function onWorkerStop(\swoole_http_server $server, $workerId)
    {

    }

    public function onWorkerExit(\swoole_http_server $server, $workerId)
    {

    }

    public function onWorkerError(\swoole_http_server $server, $workerId, $workerPId, $exitCode, $signal)
    {

    }

}
