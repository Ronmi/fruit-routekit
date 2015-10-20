<?php

namespace Fruit\RouteKit;

use Alom\Graphviz\Digraph;

/**
 * Mux is where you place routing rules and dispatch request according to these rules.
 */
class Mux implements Router
{
    private $roots;

    public function __construct()
    {
        $this->roots = array();
    }

    /**
     * Calling right handler/controller according to http method and request uri.
     *
     * @param $methond string of http request method, case insensitive.
     * @param $url string of request uri
     * @return whatever you return in the handler/controller, or an exception if no rule matched.
     */
    public function dispatch($method, $url)
    {
        $method = strtolower($method);
        if (! isset($this->roots[$method])) {
            throw new \Exception('No matching method of ' . $method);
        }
        $cur = $this->roots[$method];
        $arr = explode('/', $url);
        array_shift($arr);
        $params = array();
        while (count($arr) > 0) {
            list($cur, $param) = $cur->match(array_shift($arr));
            if ($cur == null) {
                throw new \Exception('No Matching handler for ' . $url);
            }
            if ($param != null) {
                $params[] = $param;
            }
        }
        if ($cur->handler == null) {
            throw new \Exception('No Matching handler for ' . $url);
        }

        list($cb, $args) = $cur->handler;

        if (is_array($cb)) {
            $ref = new \ReflectionClass($cb[0]);
            $obj = null;
            if (is_array($args) and count($args) > 0) {
                $obj = $ref->newInstanceArgs($args);
            } else {
                $obj = $ref->newInstance();
            }
            $cb[0] = $obj;
        }
        if (count($params) > 0) {
            return call_user_func_array($cb, $params);
        } else {
            return call_user_func($cb);
        }
    }

    private function add($method, $path, $handler, array $constructorArgs = null)
    {
        // initialize root node
        if (!isset($this->roots[$method])) {
            $this->roots[$method] = new Node;
        }
        $root = $this->roots[$method];

        $cur = $root;
        $arr = explode('/', $path);
        array_shift($arr);
        while (count($arr) > 0) {
            $cur_path = array_shift($arr);
            $cur = $cur->register($cur_path);
        }
        if ($cur->handler != null) {
            throw new \Exception('Already registered a handler for ' . $path);
        }
        $cur->handler = array($handler, $constructorArgs);
        return $this;
    }

    public function get($path, $handler, array $constructorArgs = null)
    {
        return $this->add('get', $path, $handler, $constructorArgs);
    }

    public function post($path, $handler, array $constructorArgs = null)
    {
        return $this->add('post', $path, $handler, $constructorArgs);
    }

    public function put($path, $handler, array $constructorArgs = null)
    {
        return $this->add('put', $path, $handler, $constructorArgs);
    }

    public function delete($path, $handler, array $constructorArgs = null)
    {
        return $this->add('delete', $path, $handler, $constructorArgs);
    }

    public function option($path, $handler, array $constructorArgs = null)
    {
        return $this->add('option', $path, $handler, $constructorArgs);
    }

    public function any($path, $handler, array $constructorArgs = null)
    {
        $this->add('get', $path, $handler, $constructorArgs);
        $this->add('post', $path, $handler, $constructorArgs);
        $this->add('put', $path, $handler, $constructorArgs);
        $this->add('delete', $path, $handler, $constructorArgs);
        $this->add('option', $path, $handler, $constructorArgs);
        return $this;
    }

    /**
     * Generate graphviz dot diagram
     */
    public function dot()
    {
        $ret = array();
        foreach ($this->roots as $k => $root) {
            $g = new Digraph($k);
            $root->dot($g);
            $ret[$k] = $g->render();
        }
        return $ret;
    }

    /**
     * Generate static router, convert every dynamic call to handler/controller to static call.
     *
     * This method will generate the defination of a customed class, which implements
     * Fruit\RouteKit\Router, so you can create an instance and use the dispatch() method.
     *
     * @param $clsName string custom class name, default to 'FruitRouteKitGeneratedMux'.
     * @param $indent string how you indent generated class.
     */
    public function compile($clsName = '', $indent = '')
    {
        if ($clsName == '') {
            $clsName = 'FruitRouteKitGeneratedMux';
        }
        $funcs = array();
        $disp = array();
        $ind = $indent . $indent;
        $in3 = $ind . $indent;
        $in4 = $in3 . $indent;
        $stateMap = array();
        foreach ($this->roots as $m => $root) {
            $root->fillID(0);
            $arr = $root->compile();
            $stateMap[$m] = $root->stateTable(array());

            $f = $indent . sprintf('private function dispatch%s($uri)', strtoupper($m)) . "\n";
            $f .= $indent . "{\n";
            $f .= $ind . '$method = ' . var_export($m, true) . ";\n";
            $f .= $ind . '$arr = explode(\'/\', $uri);' . "\n";
            $f .= $ind . '$arr[] = \'\';' . "\n";
            $f .= $ind . '$state = 0;' . "\n";
            $f .= $ind . '$sz = count($arr);' . "\n";
            $f .= $ind . 'for ($i = 1; $i < $sz; $i++) {' . "\n";
            $f .= $in3 . '$part = $arr[$i];' . "\n";
            $f .= $in3 . 'if (isset($this->stateMap[$method][$state][$part])) ' .
                '{$state = $this->stateMap[$method][$state][$part]; continue;}' . "\n";
            $f .= $in3 . 'switch ($state) {' . "\n";
            $f .= $in3 . implode("\n" . $in3, $arr) . "\n";
            $f .= $in3 . "default:\n";
            $f .= $in4 . 'throw new \Exception("no matching rule for url [" . $uri . "]");' . "\n";
            $f .= $in3 . "}\n";
            $f .= $ind . "}\n";
            $f .= $ind . 'throw new Exception(\'No matching rule for \' . $uri);' . "\n";
            $f .= $indent . "}\n";
            $funcs[$m] = $f;
            $disp[] = sprintf('if ($method == %s) {', var_export($m, true));
            $disp[] = sprintf($indent . 'return $this->dispatch%s($uri);', strtoupper($m));
            $disp[] = "}";
        }

        $ret = '<' . "?php\n\n";
        $ret .= 'class ' . $clsName . ' implements Fruit\RouteKit\Router' . "\n";
        $ret .= "{\n";
        $ret .= $indent . 'private $stateMap;' . "\n\n";
        $ret .= $indent . "public function __construct()\n";
        $ret .= $indent . "{\n";
        $ret .= $ind . '$this->stateMap = ' . var_export($stateMap, true) . ";\n";
        $ret .= $indent . "}\n\n";

        foreach ($funcs as $f) {
            $ret .= $f . "\n";
        }
        $ret .= $indent . 'public function dispatch($method, $uri)' . "\n";
        $ret .= $indent . "{\n";
        $ret .= $ind . '$method = strtolower($method);' . "\n";
        $ret .= $ind . implode("\n" . $ind, $disp) . "\n";
        $ret .= $indent . "}\n";
        $ret .= "}\n\nreturn new $clsName;";
        return $ret;
    }
}