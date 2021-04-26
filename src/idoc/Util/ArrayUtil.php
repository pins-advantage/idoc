<?php

namespace OVAC\IDoc\Util;

class ArrayUtil
{
    public static function set(array &$array, string $path, $value)
    {
        $temp = &$array;
        $exploded = explode('.', trim($path, "."));

        foreach ($exploded as $key) {
            $temp = &$temp[$key];
        }

        $temp = $value;
        unset($temp);
        return $array;
    }

    public static function get(
        array $input,
        $path,
        $default = null,
        array $fallbackKeys = [],
        bool $shouldThrow = false,
        bool $allowNull = false)
    {
        if (is_string($path)) {
            $path = explode('.', $path);
        }

        if (count($path) == 0) {
            return $input;
        }

        $key = array_shift($path);

        if (array_key_exists($key, $input)) {
            if (is_array($input[$key])) {
                return static::get($input[$key], $path);
            }

            if (is_null($input[$key]) && !$allowNull) {
                return $default;
            }

            return $input[$key];
        }

        foreach ($fallbackKeys as $fallbackKey) {
            if ($fallback = static::get($input, $fallbackKey)) {
                return $fallback;
            }
        }

        if ($shouldThrow) {
            throw new \Exception("Item at path {$key} not found in array");
        }

        return $default;
    }

    public static function getOrFail(array $input, $path, array $fallbackKeys = [])
    {
        return static::get($input, $path, true, $fallbackKeys);
    }

    public static function prune(array $array, bool $shouldUseEmptyFn = true)
    {
        if ($shouldUseEmptyFn) {
            return array_filter($array,
                function($v) {
                    return !empty($v);
                });
        }

        return array_filter($array,
            function($v) {
                return !is_null($v);
            });
    }

    /**
     * Flatten nested array to a single-level array of keys separated by a separator
     * defaulting to a period (.). E.g [ 'some.nested.item' => 'value' ]
     *
     * @param array $array
     * @param string $separator
     * @return array
     */
    public static function flatten(array $array, $separator = '.'): array
    {
        $recursiveIterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($array));
        $result = array();

        foreach ($recursiveIterator as $leafValue) {
            $keys = array();
            foreach (range(0, $recursiveIterator->getDepth()) as $depth) {
                $keys[] = $recursiveIterator->getSubIterator($depth)->key();
            }
            $result[join($separator, $keys)] = $leafValue;
        }

        return $result;
    }
}
