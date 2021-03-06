<?php

namespace FruitTest\RouteKit;

class Handler
{
    private $data;
    private $inject;

    public function __construct()
    {
        $this->data = func_get_args();
    }

    public function get()
    {
        return 'get';
    }

    public function post()
    {
        return 'post';
    }

    public function basic()
    {
        return 1;
    }

    public function params()
    {
        return func_get_args();
    }

    public function constructArgs()
    {
        return $this->data;
    }

    public function inject($inj)
    {
        $this->inject = $inj;
    }

    public function inj()
    {
        return $this->inject;
    }

    public function filter()
    {
        return 'original';
    }
}
