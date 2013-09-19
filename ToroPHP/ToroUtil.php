<?php
namespace ToroPHP;

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
