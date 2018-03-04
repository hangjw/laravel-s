<?php

namespace Hangjw\LaravelS\Illuminate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class LaravelSCommand extends Command
{
    protected $signature = 'laravels';

    protected $description = 'LaravelS Console Tool';

    protected $actions;

    protected $configKey;

    const LARAVEL_HTTP = 'laravelsHttp';
    const LARAVEL_WEBSOCKET = 'laravelsWebsocket';

    const TYPE = [
        self::LARAVEL_HTTP => 'http',
        self::LARAVEL_WEBSOCKET => 'websocket',
    ];

    protected $isLumen = false;

    public function __construct()
    {
        $this->actions = ['start', 'stop', 'restart', 'reload', 'publish'];
        $actions = implode('|', $this->actions);
        $this->signature .= sprintf(' {action : %s}', $actions);
        $this->description .= ': ' . $actions;

        parent::__construct();
    }

    public function fire()
    {
        $this->handle();
    }

    public function handle()
    {
        $action = (string)$this->argument('action');
        $param = explode(':', $action);
        $action = $param[0] ?? '';
        $type = $param[1] ?? null;

        if ($type == self::TYPE[self::LARAVEL_WEBSOCKET]) {
            $this->configKey = self::LARAVEL_WEBSOCKET;
        } else {
            $this->configKey = self::LARAVEL_HTTP;
        }
        if (!in_array($action, $this->actions, true)) {
            $this->warn(sprintf($this->configKey . ': action %s is not available, only support %s', $action, implode('|', $this->actions)));
            return;
        }

        $this->isLumen = stripos($this->getApplication()->getVersion(), 'Lumen') !== false;
        $this->loadConfigManually();
        if ($type) {
            $this->{$action}($type);
        } else {
            $this->{$action}();
        }

    }

    protected function loadConfigManually()
    {
        // Load configuration laravel.php manually for Lumen
        if ($this->isLumen && file_exists(base_path('config/' . $this->configKey . '.php'))) {
            $this->getLaravel()->configure($this->configKey);
        }
    }

    protected function outputLogo()
    {
        static $logo = <<<EOS
 _                               _  _____ 
| |                             | |/ ____|
| |     __ _ _ __ __ ___   _____| | (___  
| |    / _` | '__/ _` \ \ / / _ \ |\___ \ 
| |___| (_| | | | (_| |\ V /  __/ |____) |
|______\__,_|_|  \__,_| \_/ \___|_|_____/ 
                                           
EOS;
        $this->info($logo);
        $this->info('Speed up your Laravel/Lumen');
        $this->table(['Component', 'Version'], [
            ['Component' => 'PHP', 'Version' => phpversion()],
            ['Component' => 'Swoole', 'Version' => \swoole_version()],
            ['Component' => $this->getApplication()->getName(), 'Version' => $this->getApplication()->getVersion()],
        ]);
    }

    protected function start()
    {
        $this->outputLogo();
        $svrConf = config($this->configKey);
        if (empty($svrConf['swoole']['document_root'])) {
            $svrConf['swoole']['document_root'] = base_path('public');
        }
        if (empty($svrConf['process_prefix'])) {
            $svrConf['process_prefix'] = base_path();
        }
        $laravelConf = [
            'rootPath' => base_path(),
            'staticPath' => $svrConf['swoole']['document_root'],
            'isLumen' => $this->isLumen,
        ];

        if (file_exists($svrConf['swoole']['pid_file'])) {
            $pid = (int)file_get_contents($svrConf['swoole']['pid_file']);
            if ($this->killProcess($pid, 0)) {
                $this->warn(sprintf($this->configKey . ': PID[%s] is already running at %s:%s.', $pid, $svrConf['listen_ip'], $svrConf['listen_port']));
                return;
            }
        }

        // Implements gracefully reload, avoid including laravel's files before worker start
        $cmd = sprintf('%s %s/../GoLaravelS.php', PHP_BINARY, __DIR__);
        $bootType = $this->configKey;
        $ret = $this->popen($cmd, json_encode(compact('svrConf', 'laravelConf', 'bootType')));
        if ($ret === false) {
            $this->error($this->configKey . ': popen ' . $cmd . ' failed');
            return;
        }
        $pidFile = config($this->configKey . '.swoole.pid_file');

        // Make sure that master process started
        $time = 0;
        while (!file_exists($pidFile) && $time <= 20) {
            usleep(100000);
            $time++;
        }
        if (file_exists($pidFile)) {
            $this->info(sprintf($this->configKey . ': PID[%s] is running at %s:%s.', file_get_contents($pidFile), $svrConf['listen_ip'], $svrConf['listen_port']));
        } else {
            $this->error(sprintf($this->configKey . ': PID file[%s] does not exist.', $pidFile));
        }
    }

    protected function popen($cmd, $input = null)
    {
        $fp = popen($cmd, 'w');
        if ($fp === false) {
            return false;
        }
        if ($input !== null) {
            fwrite($fp, $input);
        }
        pclose($fp);
        return true;
    }

    protected function stop()
    {
        $pidFile = config($this->configKey . '.swoole.pid_file');
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if ($this->killProcess($pid, 0)) {
                if ($this->killProcess($pid, SIGTERM)) {
                    // Make sure that master process quit
                    $time = 0;
                    while ($this->killProcess($pid, 0) && $time <= 20) {
                        usleep(100000);
                        $this->killProcess($pid, SIGTERM);
                        $time++;
                    }
                    if (file_exists($pidFile)) {
                        unlink($pidFile);
                    }
                    $this->info($this->configKey . ": PID[{$pid}] is stopped.");
                } else {
                    $this->error($this->configKey . ": PID[{$pid}] is stopped failed.");
                }
            } else {
                $this->warn($this->configKey . ": PID[{$pid}] does not exist, or permission denied.");
                if (file_exists($pidFile)) {
                    unlink($pidFile);
                }
            }
        } else {
            $this->info($this->configKey . ': already stopped.');
        }
    }

    protected function restart()
    {
        $this->stop();
        $this->start();
    }

    protected function reload()
    {
        $pidFile = config($this->configKey . '.swoole.pid_file');
        if (!file_exists($pidFile)) {
            $this->error($this->configKey . ': it seems that ' . $this->configKey . ' is not running.');
            return;
        }

        $pid = (int)file_get_contents($pidFile);
        if (!$this->killProcess($pid, 0)) {
            $this->error($this->configKey . ": PID[{$pid}] does not exist, or permission denied.");
            return;
        }

        if ($this->killProcess($pid, SIGUSR1)) {
            $this->info($this->configKey . ": PID[{$pid}] is reloaded.");
        } else {
            $this->error($this->configKey . ": PID[{$pid}] is reloaded failed.");
        }
    }

    protected function publish()
    {
        foreach (['laravels.php', 'laravelsWebsocket.php'] as $file) {
            $to = base_path('config/' . $file);
            if (file_exists($to)) {
                $choice = $this->anticipate($to . ' already exists, do you want to override it ? Y/N', ['Y', 'N'], 'N');
                if (!$choice || strtoupper($choice) !== 'Y') {
                    $this->info('Publishing skipped.');
                    return;
                }
            }

            try {
                $this->call('vendor:publish', ['--provider' => LaravelSServiceProvider::class, '--force' => true]);
                return;
            } catch (\Exception $e) {
                if (!($e instanceof \InvalidArgumentException)) {
                    throw $e;
                }
            }
            $from = __DIR__ . '/../Config/' . $file;

            $toDir = dirname($to);


            /**
             * @var Filesystem $files
             */
            $files = app(Filesystem::class);

            if (!$files->isDirectory($toDir)) {
                $files->makeDirectory($toDir, 0755, true);
            }

            $files->copy($from, $to);

            $from = str_replace(base_path(), '', realpath($from));

            $to = str_replace(base_path(), '', realpath($to));

            $this->line('<info>Copied File</info> <comment>[' . $from . ']</comment> <info>To</info> <comment>[' . $to . ']</comment>');
        }

    }

    protected function killProcess($pid, $sig)
    {
        try {
            return \swoole_process::kill($pid, $sig);
        } catch (\Exception $e) {
            return false;
        }
    }
}
