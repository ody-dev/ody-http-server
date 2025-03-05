<?php
declare(strict_types=1);

namespace Ody\HttpServer;

use Ody\Swoole\HotReload\Watcher;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response;
use Swoole\Http\Server as SwooleServer;
use Swoole\Process;
use Swoole\Runtime;

/**
 * @psalm-api
 */
class Server
{
    /**
     * @var SwooleServer
     */
    private SwooleServer $server;

    /**
     * Starts the server
     *
     * @return void
     */
    public function start(): void
    {
        $this->server->start();
    }

    /**
     * @return Server
     */
    public static function init(): Server
    {
        return new self();
    }

    /**
     * @param bool $daemonize
     * @param bool $watcher
     * @return Server
     */
    public function createServer(bool $daemonize, bool $watcher): Server
    {
        $this->server = new SwooleServer(
            config('server.host', "127.0.0.1"),
            (int) config('server.port', 9501),
            $this->getSslConfig(),
            config('server.sock_type', SWOOLE_SOCK_TCP)
        );

        if(config('server.runtime.enable_coroutine')) {
            Runtime::enableCoroutine(
                config('server.runtime.hook_flag')
            );
        }

        $this->server->set([
            ...config('server.additional'),
            'daemonize' => (int) $daemonize,
            'enable_coroutine' => false // must be set on false for Runtime::enableCoroutine
        ]);

        $this->registerCallbacks(
            config('server.callbacks')
        );

        if ($watcher) {
            (new Process(function (Process $process) {
                HttpServerState::getInstance()
                    ->setWatcherProcessId($process->pid);
                (new Watcher())->start();
            }))->start();

            echo "   \033[1mINFO\033[0m  File watcher is enabled\n";
        }

        return $this;
    }

    /**
     * @param SwooleServer $server
     * @return void
     */
    public static function onStart (SwooleServer $server): void
    {
        $protocol = ($server->ssl) ? "https" : "http";
        echo "   \033[1mSUCCESS\033[0m  http server started successfully\n";
        echo "   \033[1mINFO\033[0m  listen on " . $protocol . "://" . $server->host . ':' . $server->port . PHP_EOL;
        echo "   \033[1mINFO\033[0m  press Ctrl+C to stop the server\n";
    }

    /**
     * @param SwooleRequest $request
     * @param Response $response
     * @return void
     */
    public static function onRequest(SwooleRequest $request, Response $response): void
    {

    }

    /**
     * @param SwooleServer $server
     * @param int $workerId
     * @return void
     */
    public static function onWorkerStart(SwooleServer $server, int $workerId): void
    {
        // Save worker ids to serverState.json
        if ($workerId == config('server.additional.worker_num') - 1) {
            $workerIds = [];
            for ($i = 0; $i < config('server.additional.worker_num'); $i++) {
                $workerIds[$i] = $server->getWorkerPid($i);
            }

            $serveState = HttpServerState::getInstance();
            $serveState->setMasterProcessId($server->getMasterPid());
            $serveState->setManagerProcessId($server->getManagerPid());
            $serveState->setWorkerProcessIds($workerIds);
        }
    }

    /**
     * @param SwooleServer $server
     * @return void
     */
    public static function onManagerStart(SwooleServer $server)
    {

    }

    /**
     * @param SwooleServer $server
     * @return void
     */
    public static function onManagerStop(SwooleServer $server)
    {

    }

    public static function onReceive(SwooleServer $server, int $fd, int $reactorId, string $data)
    {
        dd($data);
    }

    /**
     * @param SwooleServer $server
     * @param int $workerId
     * @param int $worker_id
     * @param int $worker_pid
     * @param int $exit_code
     * @param int $signal
     * @return void
     */
    public static function onWorkerError(SwooleServer $server, int $workerId, int $worker_id, int $worker_pid, int $exit_code): void
    {
        dd('WorkerError', $workerId);
    }

    /**
     * @return int
     */
    private function getSslConfig(): int
    {
        if (
            !is_null(config('server.ssl.ssl_cert_file')) &&
            !is_null(config('server.ssl.ssl_key_file'))
        ) {
            return config('server.mode', SWOOLE_SSL) | SWOOLE_SSL;
        }

        return config('server.mode');
    }

    public function getServer(): SwooleServer
    {
        return $this->server;
    }

    private function registerCallbacks(array $callbacks): void
    {
        foreach ($callbacks as $event => $callback) {
            $this->server->on($event, [...$callback]);
        }
    }
}
