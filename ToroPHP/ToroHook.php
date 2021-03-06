<?php
namespace ToroPHP;

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

    public static function isAdded ($hook_name)
    {
        $instance = self::getInstance();
        return isset($instance->hooks[$hook_name]);
    }

    public static function fire($hook_name, $params = null)
    {
        $instance = self::getInstance();
        if (self::isAdded($hook_name)) {
            foreach ($instance->hooks[$hook_name] as $fn) {
                return call_user_func_array($fn, array(&$params));
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
