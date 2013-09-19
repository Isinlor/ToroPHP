<?php
namespace ToroPHP;

class Toro
{
    // used in ToroUtil::urlFor to resolve handlers urls
    private static $routes;

    // and the method to return the routes
    public static function getRoutes()
    {
        return self::$routes;
    }

    public static function getPathInfo()
    {
        $path_info = '/';
        if (!empty($_SERVER['PATH_INFO'])) {
            $path_info = $_SERVER['PATH_INFO'];
        } elseif (!empty($_SERVER['ORIG_PATH_INFO']) && $_SERVER['ORIG_PATH_INFO'] !== '/index.php') {
            $path_info = $_SERVER['ORIG_PATH_INFO'];
        } else {
            if (!empty($_SERVER['REQUEST_URI'])) {
                $path_info = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
                if (!empty($_SERVER['SCRIPT_NAME'])) {
                    $path_info = str_replace($_SERVER['SCRIPT_NAME'], "/", $path_info);
                    if (strpos($_SERVER['SCRIPT_NAME'], "/index.") !== false) {
                        $script_dir = strstr($_SERVER['SCRIPT_NAME'], '/index.', true);
                        $path_info = str_replace($script_dir, "/", $path_info);
                    }
                }
            }
        }

        return $path_info;
    }

    // route convenience tokens
    public static function getTokens()
    {
        return array(
            ':string' => '([a-zA-Z]+)',
            ':number' => '([0-9]+)',
            ':alpha' => '([a-zA-Z0-9-_]+)'
        );
    }

    public static function serve($routes)
    {
        self::$routes = $routes;

        ToroHook::fire('before_request', compact('routes'));

        $request_method = strtolower($_SERVER['REQUEST_METHOD']);

        $path_info = self::getPathInfo();

        $discovered_handler = null;
        $regex_matches = array();

        if (isset($routes[$path_info])) {
            $discovered_handler = $routes[$path_info];
        } elseif ($routes) {

            $tokens = self::getTokens();

            foreach ($routes as $pattern => $handler_name) {
                $pattern = strtr($pattern, $tokens);
                if (preg_match('#^/?' . $pattern . '/?$#', $path_info, $matches)) {
                    $discovered_handler = $handler_name;
                    $regex_matches = $matches;
                    break;
                }
            }
        }

        $result = null;
        if ($discovered_handler && class_exists($discovered_handler)) {
            unset($regex_matches[0]);
            $handler_instance = new $discovered_handler();

            if (self::isXhrRequest() && method_exists($discovered_handler, $request_method . '_xhr')) {
                self::setXhrResponseHeaders();
                $request_method .= '_xhr';
            }

            if (method_exists($handler_instance, $request_method)) {
                ToroHook::fire('before_handler', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
                $result = call_user_func_array(array($handler_instance, $request_method), $regex_matches);
                ToroHook::fire('after_handler', compact('routes', 'discovered_handler', 'request_method', 'regex_matches', 'result'));
            } else {
                ToroHook::fire('404', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
            }
        } else {
            ToroHook::fire('404', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
        }

        ToroHook::fire('after_request', compact('routes', 'discovered_handler', 'request_method', 'regex_matches', 'result'));
    }

    private static function isXhrRequest()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    private static function setXhrResponseHeaders()
    {
        header('Content-type: application/json');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
    }
}

class ToroHook
{
    private static $instance;

    private $hooks = array();

    private function __construct() {}
    private function __clone() {}

    public static function add($hook_name, $fn)
    {
        $instance = self::getInstance();
        $instance->hooks[$hook_name][] = $fn;
    }

    public static function fire($hook_name, $params = null)
    {
        $instance = self::getInstance();
        if (isset($instance->hooks[$hook_name])) {
            foreach ($instance->hooks[$hook_name] as $fn) {
                call_user_func_array($fn, array(&$params));
            }
        }
    }

    public static function getInstance()
    {
        if (empty(self::$instance)) {
            self::$instance = new ToroHook();
        }

        return self::$instance;
    }
}

class ToroUtil
{

    /* only used for testing */
    public static $routes = array();

    /*
    * Tries to return the url for a handler if the handler exists.
    * If the route of this handler was defined using parameters
    * then an array with the parameters in the same order is expected.
    *
    * example:
    *
    * Toro::serve(array(
    * "/" => "IndexHandler",
    * "/hello/:alpha" => "HelloHandler",
    * "/test/this" => "TestHandler",
    * ));
    *
    * ToroUtil::urlFor("IndexHandler") would return "/"
    * ToroUtil::urlFor("HelloHandler", array("test")) would return "/hello/test"
    *
    * but
    *
    * ToroUtil::urlFor("HelloHandler") will not return because it's missing the
    * :alpha parameter
    *
    * and
    *
    * ToroUtil::urlFor("TestHandler") would return "/test/this"
    *
    * This is because routes can change so it is better
    * to define them only in one place (DRY)
    *
    */

    public static function urlFor($handler, $params = array())
    {
        $tokens = Toro::getTokens();
        $routes = count(self::$routes) > 0 ? self::$routes : Toro::getRoutes();

        foreach ($routes as $pattern => $handler_name) {
            if ($handler_name == $handler) {

                /* convert the tokens like :string to regex like ([a-zA-Z]+) */
                $pattern = strtr($pattern, $tokens);
                /* find all the regex parameters in the route pattern */
                preg_match_all('#\([^\(\)]+\)#', $pattern, $regs);
                /*
                * Replaces all the regex patterns in the defined route for this handler
                * with their parameter counterparts
                *
                * If the defined route for this handler is something like /hello/:alpha-:alpha
                * and the reverse route is required, having specified an array of parameters
                * like array("this", "that") the resulting url should be /hello/this-that
                *
                * Now, :alpha get's transformed to ([a-zA-Z0-9-_]+)
                * and then it is replaced with the parameter in the array
                * first occurence of the regex is replaced with the first parameter
                * the second occurence with the second parameter and so on..
                *
                * Finally, the resulting string is returned if the original pattern
                * for the handler matches it.
                */

                if (isset($regs[0])) {
                    $url = $pattern;
                    for ($i=0; $i < count($regs[0]); $i++) {
                        $pat = $regs[0][$i];
                        if (isset($params[$i])) {
                            $par = $params[$i];
                            $pos = strpos($url, $pat);
                            if ($pos != false) {
                                $url = substr_replace($url, $par, $pos, strlen($pat));
                            }
                        }

                    }
                }

                /*
                * test that the route pattern matches the resulting url
                * to validate that the correct number and type of parameters
                * were received
                */

                if (preg_match('#^/?' . $pattern . '/?$#', $url)) {
                    return $url;
                }
            }
        }
    }
}
