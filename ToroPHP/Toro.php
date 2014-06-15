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

    protected static function setHookInvokeHandler()
    {
        ToroHook::add("invoke_handler", function($handler) {
            return new $handler();
        });
    }

    protected static function setHookCallRequestedMethod()
    {
        ToroHook::add("call_requested_method", function($options) {
            return call_user_func_array($options[0], $options[1]);
        });
    }

    public static function serve($routes)
    {
        self::$routes = $routes;

        ToroHook::fire('before_request', compact('routes'));

        if(!ToroHook::isAdded("invoke_handler")){
            self::setHookInvokeHandler();
        }
        if(!ToroHook::isAdded("call_requested_method")){
            self::setHookCallRequestedMethod();
        }

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

            $handler_instance = ToroHook::fire('invoke_handler', $discovered_handler);

            if (self::isXhrRequest() && method_exists($discovered_handler, $request_method . '_xhr')) {
                self::setXhrResponseHeaders();
                $request_method .= '_xhr';
            }

            if (method_exists($handler_instance, $request_method)) {
                ToroHook::fire('before_handler', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
                $result =  ToroHook::fire('call_requested_method', array(array($handler_instance, $request_method), $regex_matches));
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
