<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Utils;

/**
 * several utils for common issues
 *
 * @author alfons
 */
class Utils {

    public static function startsWith($haystack, $needle) {
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }

    public static function endsWith($haystack, $needle) {
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
    }

    public static function isLocalIp($ip) {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) || ($ip == '127.0.0.1');
    }

    public function redirect($location) {
        header('location:' . $location);
        exit();
    }

    public static function cleanUpString($str, $replace = array(), $delimiter = '-') {
        if (!empty($replace)) {
            $str = str_replace((array) $replace, '', $str);
        }
        $clean = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        $clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $clean);
        $clean = strtolower(trim($clean, '-'));
        $clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);
        return trim($clean);
    }

    public static function titleize($text) {
        $result = ucfirst(str_replace('_', ' ', trim($text)));
        return $result;
    }

    public static function pascalize($text) {
        $parts = explode('_', strtolower(trim($text)));
        $result = '';
        foreach ($parts as $part) {
            $result .= ucfirst($part);
        }
        return $result;
    }

    public static function camelize($text) {
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

    public static function decamelize($text) {
        return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', trim($text)));
    }

    public static function explodeByNewline($text) {
        $text = str_replace("\r", "", $text);
        $text = explode("\n", $text);
        return $text;
    }

    public static function yesterday() {
        return date('Y-m-d', strtotime("-1 days"));
    }

    public static function br2nl($string) {
        return preg_replace('/\<br(\s*)?\/?\>/i', "\n", $string);
    }

    public static function html2text($string) {
        $string = static::br2nl($string);
        $string = strip_tags($string);
        $string = html_entity_decode($string);
        $string = preg_replace_callback("/(&#[0-9]+;)/",
            function($matches) {
                return mb_convert_encoding($matches[1], "UTF-8", "HTML-ENTITIES");
            }, $string);
        return trim($string);
    }

    public static function uniqueFilename($name) {
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

    public static function humanByteSize($size, $unit = "") {
        if ((!$unit && $size >= 1 << 30) || $unit == "GB")
            return number_format($size / (1 << 30), 2) . "GB";
        if ((!$unit && $size >= 1 << 20) || $unit == "MB")
            return number_format($size / (1 << 20), 2) . "MB";
        if ((!$unit && $size >= 1 << 10) || $unit == "KB")
            return number_format($size / (1 << 10), 2) . "KB";
        return number_format($size) . " bytes";
    }

    public static function isCli() {
        return php_sapi_name() === 'cli';
    }

    public static function isRoot() {
        return posix_getuid() == 0;
    }

}
