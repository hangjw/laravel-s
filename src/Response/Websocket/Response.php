<?php
namespace Hangjw\LaravelS\Response\Websocket;

class Response
{
    protected $ws;
    protected $fd;

    protected $laravelResponse;

    public function __construct($ws)
    {
        $this->ws = $ws;
    }

    public function setFd($request)
    {
        $this->fd = $request;
        return $this;
    }

    public function send($content='', $all=true)
    {
        if (!is_string($content)) {
            $content = json_encode($content);
        }
        if ($all === false) {
            $this->ws->push($this->fd,  $content);
        } elseif ($all !== true) {
            $this->ws->push($all,  $content);
        } else {
            foreach($this->ws->connections as $fd) {
                $this->ws->push($fd, $content);
            }
        }
    }
}
