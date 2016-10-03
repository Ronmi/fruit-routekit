<?php

namespace Fruit\RouteKit;

use Alom\Graphviz\Digraph;
use CodeGen\UserClass;
use Exception;
use ReflectionClass;
use ReflectionParameter;

/**
 * Mux is where you place routing rules and dispatch request according to these rules.
 */
class Mux implements Router
{
    private $roots;
    private $interceptor;
    private $currentFilters;

    public function __construct()
    {
        $this->roots = array();
        $this->currentFilters = array(array(), array());
    }

    /**
     * Set input and output filters
     *
     * Input filter is nothing but a callable, which accepts 4 parameters:
     * http method, uri, handler callback and handler parameters. Returning
     * anything other than NULL will keep handler from executing, and showing
     * the result to user.
     *
     * Input filter is mean to filter the work flow, so it SHOULD NOT modify
     * these parameters.
     *
     * Output filter is mean to filter data. It is a simple function accepts
     * the result of handler, manipulate on it, then return it back. Anything
     * returned from it will show to user, including NULL.
     */
    public function setFilters(array $input = array(), array $output = array())
    {
        // validate input filters
        foreach ($input as $i) {
            list($f) = Util::reflectionCallable($i);
            if (count($f->getParameters()) !== 4) {
                throw new Exception('Input filter must accepts exactly 4 parameters');
            }
        }
        // validate output filters
        foreach ($output as $o) {
            list($f) = Util::reflectionCallable($o);
            if (count($f->getParameters()) !== 1) {
                throw new Exception('Output filter must accepts exactly 1 parameter');
            }
        }
        $this->currentFilters = array($input, $output);

        return $this;
    }

    public function getFilters()
    {
        return $this->currentFilters;
    }

    /**
     * Set interceptor function.
     *
     * Interceptor is a function running right before calling
     * class-based controller. It MUST accepts exactly three
     * parameters: matched url, class name (for static controller)
     * or object (for method controller), and method name.
     *
     * Interceptor is designed to do change internal state of controller
     * before running it, like injecting deps. It's not the entry point
     * of middleware.
     */
    public function setInterceptor($int)
    {
        list($f) = Util::reflectionCallable($int);
        if (count($f->getParameters()) !== 3) {
            throw new Exception('Interceptor must accepts exactly three parameters');
        }

        $this->interceptor = $int;
        return $this;
    }

    /**
     * Calling right handler/controller according to http method and request uri.
     *
     * @param $method string of http request method, case insensitive.
     * @param $url string of request uri
     * @return whatever you return in the handler/controller, or an exception if no rule matched.
     */
    public function dispatch($method, $url)
    {
        $method = strtolower($method);
        if (! isset($this->roots[$method])) {
            throw new Exception('No matching method of ' . $method);
        }
        $cur = $this->roots[$method];
        $arr = explode('/', $url);
        array_shift($arr);
        $params = array();
        $arrSize = count($arr);
        for ($i = 0; $i < $arrSize; $i++) {
            list($cur, $param) = $cur->match($arr[$i]);
            if ($cur == null) {
                throw new Exception('No Matching handler for ' . $url);
            }
            if ($param != null) {
                $params[] = $param;
            }
        }
        list($cb, $params) = $cur->prepare($url, $params, $this->interceptor);
        list($i, $o) = $cur->getFilters();
        foreach ($i as $filter) {
            $ret = $filter($method, $url, $cb, $params);
            if ($ret !== null) {
                return $ret;
            }
        }

        $ret = call_user_func_array($cb, $params);

        foreach ($o as $filter) {
            $ret = $filter($ret);
        }
        return $ret;
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
            $curPath = array_shift($arr);
            $cur = $cur->register($curPath);
        }
        $old = $cur->getHandler();
        $h = [$handler, $constructorArgs];
        if ($old !== null and $old !== $h) {
            throw new Exception('Already registered a handler for ' . $path);
        } else {
            $cur->setHandler(array($handler, $constructorArgs));
        }

        // add filters
        list($input, $output) = $this->currentFilters;
        foreach ($input as $i) {
            $cur->addInputFilter($i);
        }
        foreach ($output as $o) {
            $cur->addOutputFilter($o);
        }

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

    private function doCompile($clsName = '', $indent = '    ')
    {
        if ($clsName == '') {
            $clsName = 'Fruit\RouteKit\GeneratedMux';
        }

        $gen = new UserClass($clsName);
        $gen->implementInterface('Fruit\RouteKit\Router');

        $in1 = $indent;
        $in2 = $in1 . $in1;
        $stateMap = array();
        $varMap = array();
        $funcMap = array();
        $funcCnt = 0;
        foreach ($this->roots as $m => $root) {
            $root->fillID(0);
            $stateMap[$m] = $root->stateTable(array());
            $varMap[$m] = $root->varTable(array());
            $funcMap[$m] = $root->funcTable(array(), 0, $this->interceptor);

            // make handlers
            foreach ($funcMap[$m] as $id => $body) {
                $fn = sprintf('f%d', $funcCnt++);
                $gen->addMethod('private static', $fn, array('$method', '$url', '$params'), $body);
                $funcMap[$m][$id] = $fn;
            }

        }

        $gen->addStaticVar('stateMap', $stateMap, 'private');
        $gen->addStaticVar('varMap', $varMap, 'private');
        $gen->addStaticVar('funcMap', $funcMap, 'private');

        // make dispatcher
        $func = array();
        $func[] = 'list($f, $params) = \Fruit\RouteKit\Mux::findRoute(';
        $func[] = '    $method, $uri,';
        $func[] = '    self::$stateMap,';
        $func[] = '    self::$varMap,';
        $func[] = '    self::$funcMap';
        $func[] = ');';
        $func[] = '';
        $func[] = 'if ($f === null) {';
        $func[] = '    throw new \Exception(\'No route for \' . $uri);';
        $func[] = '}';
        $func[] = '';
        $func[] = 'return self::$f($method, $uri, $params);';
        $gen->addMethod('public static', 'execute', array('$method', '$uri'), $func);

        $gen->addMethod('public', 'dispatch', array('$method', '$uri'), $func);

        return $gen;
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
    public function compile($clsName = '', $indent = '    ')
    {
        $gen = $this->doCompile($clsName, $indent);
        return "<?php\n" . $gen->render() . "return new $clsName;\n";
    }

    public static function findRoute($method, $uri, $smap, $vmap, $fmap)
    {
        $method = strtolower($method);
        if (!isset($smap[$method])) {
            throw new \Exception('unsupported method ' . $method);
        }
        $arr = explode('/', $uri);
        $sz = count($arr);
        $stack = array(array(1, 0, array()));
        while (count($stack) > 0) {
            list($i, $state, $params) = array_pop($stack);
            if ($i === $sz) {
                if (isset($fmap[$method][$state])) {
                    return array($fmap[$method][$state], $params);
                }

                continue;
            }
            $part = $arr[$i];
            if (isset($vmap[$method][$state])) {
                $p = $params;
                $p[] = $part;
                $stack[] = array($i+1, $vmap[$method][$state], $p);
            }
            if (isset($smap[$method][$state][$part])) {
                $stack[] = array($i+1, $smap[$method][$state][$part], $params);
            }
        }
        return array(null, array());
    }

}
