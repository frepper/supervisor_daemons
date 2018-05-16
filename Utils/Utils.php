<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Utils;

/**
 * several utils for common issues
 *
 */
class Utils
{

    public static function cleanUpString($str, $replace = array(), $delimiter = '-')
    {
        if (!empty($replace)) {
            $str = str_replace((array)$replace, '', $str);
        }
        $clean = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        $clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $clean);
        $clean = strtolower(trim($clean, '-'));
        $clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);
        return trim($clean);
    }

    public static function titleize($text)
    {
        $result = ucfirst(str_replace('_', ' ', trim($text)));
        return $result;
    }

    public static function pascalize($text)
    {
        $parts = explode('_', strtolower(trim($text)));
        $result = '';
        foreach ($parts as $part) {
            $result .= ucfirst($part);
        }
        return $result;
    }

    public static function camelize($text)
    {
        $parts = explode('_', strtolower(trim($text)));
        $result = '';
        foreach ($parts as $part) {
            if (empty($result)) {
                $result = $part;
            } else {
                $result .= ucfirst($part);
            }
        }
        return $result;
    }

    public static function decamelize($text)
    {
        return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', trim($text)));
    }

    public static function uniqueFilename($name)
    {
        if (!is_file($name)) {
            return $name;
        }
        $pathInfo = pathinfo($name);
        $extension = $pathInfo['extension'];
        $folder = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $i = 1;
        while (is_file($folder . DIRECTORY_SEPARATOR . $filename . '(' . $i . ')' . '.' . $extension)) {
            $i++;
        }
        return $folder . DIRECTORY_SEPARATOR . $filename . '(' . $i . ')' . '.' . $extension;
    }

    public static function humanByteSize($size, $unit = "")
    {
        if ((!$unit && $size >= 1 << 30) || $unit == "GB")
            return number_format($size / (1 << 30), 2) . "GB";
        if ((!$unit && $size >= 1 << 20) || $unit == "MB")
            return number_format($size / (1 << 20), 2) . "MB";
        if ((!$unit && $size >= 1 << 10) || $unit == "KB")
            return number_format($size / (1 << 10), 2) . "KB";
        return number_format($size) . " bytes";
    }

    public static function isCli()
    {
        return php_sapi_name() === 'cli';
    }

    public static function isRoot()
    {
        return posix_getuid() == 0;
    }

}
