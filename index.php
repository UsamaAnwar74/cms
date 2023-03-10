<?php

/**
 * Siberian installer
 *
 * @date 03-10-2021
 * @author Xtraball <dev@xtraball.com>
 */

if (isset($_GET['phpinfo'])) {
    phpinfo();
    die;
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 *
 */
defined('BASE_PATH') || define('BASE_PATH', realpath(__DIR__));

$baseUrl = "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER["HTTP_HOST"]}";
$url = 'https://updates02.siberiancms.com/installs/get.php';
$errorMessage = "";

$archiveBasePath = BASE_PATH . '/siberiancms.source.tgz';

if (array_key_exists('sae', $_POST) &&
    $_POST['sae'] === '1') {
    try {

        //adding license to get url
        $url .= '?sae=1';

        if (version_compare(phpversion(), '7.0', '<') ||
            version_compare(phpversion(), '7.4', '>=')) {
            throw new Exception("PHP 7.0, 7.1, 7.2 or 7.3 is required, see <a target=\"_blank\" href=\"https://doc.siberiancms.com/knowledge-base/siberian-server-requirements/\">siberian-server-requirements</a> for more informations \n");
        }

        Requirements::runTest();

        if (!fileIsValid()) {
            downloadFile();
        }

        if (!fileIsValid()) {
            throw new Exception('The content of the package seems to be corrupted. Please, try again.');
        }

        extractFile();

        if (!checkContents()) {
            throw new Exception('Unable to extract the package. Please, check the exec function is not disabled and your SiberianCMS installation folder permissions.');
        }

        setPermissions();

        @unlink($archiveBasePath);

        sleep(5);
        header("Location: {$baseUrl}");
        die;
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

/**
 * @return bool
 * @throws Exception
 */
function fileIsValid()
{
    global $archiveBasePath;
    return is_file($archiveBasePath) && @md5_file($archiveBasePath) === _getMd5();
}

/**
 * @throws Exception
 */
function downloadFile()
{
    set_time_limit(900);
    ini_set('max_execution_time', 900);

    global $archiveBasePath;
    if (is_file($archiveBasePath)) {
        @unlink($archiveBasePath);
    }
    $resFileOpen = Request::get(_getFileUrl(), [], 900);
    if ($resFileOpen) {
        file_put_contents($archiveBasePath, $resFileOpen);
    } else {
        throw new Exception('Cannot download install file.');
    }
}

/**
 *
 */
function extractFile()
{
    global $archiveBasePath;
    exec('tar -xzf "' . $archiveBasePath . '"');
}

/**
 * @return bool
 */
function checkContents()
{
    global $baseUrl;

    $baseFilePath = BASE_PATH . '/ping.txt';
    $pingUrl = $baseUrl . '/ping.txt';
    $pingOk = Request::get($pingUrl) === '1';
    return is_file($baseFilePath) && $pingOk;
}

/**
 *
 */
function setPermissions()
{
    $collection = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH));
    $fullPermissionsDirectories = ['images', 'languages', 'var'];
    foreach ($collection as $item) {

        if (in_array($item->getFilename(), ['', '..', '.DS_Store', '.htaccess'], true)) {
            continue;
        }

        $path = str_replace(BASE_PATH, '', $item->getRealPath());
        if (strpos($path, '/') === 0) {
            $path = substr($path, 1, strlen($path) - 1);
        }
        if (empty($path)) {
            continue;
        }

        $part = current(explode("/", $path));
        if (empty($part)) {
            continue;
        }

        if ($item->isDir()) {
            if (in_array($part, $fullPermissionsDirectories, true)) {
                @chmod($item->getRealPath(), 0777);
            } else {
                @chmod($item->getRealPath(), 0775);
            }
        } else if ($item->isFile()) {
            if (in_array($part, $fullPermissionsDirectories, true)) {
                @chmod($item->getRealPath(), 0666);
            } else if ($item->getRealpath() === (BASE_PATH . '/index.php')) {
                @chmod($item->getRealPath(), 0644);
            } else {
                @chmod($item->getRealPath(), 0664);
            }
        }

    }
}

/**
 * @return mixed
 * @throws Exception
 */
function _getMd5()
{
    global $md5;
    if (!$md5) {
        _fetchData();
    }
    return $md5;
}

/**
 * @return mixed
 * @throws Exception
 */
function _getFileUrl()
{
    global $file_url;
    if (!$file_url) {
        _fetchData();
    }
    return $file_url;
}

/**
 * @throws Exception
 */
function _fetchData()
{
    global $url, $md5, $file_url;

    $data = Request::get($url);
    $dataDecoded = @json_decode($data);

    if ($dataDecoded &&
        isset($dataDecoded->error, $dataDecoded->message) &&
        $dataDecoded->error === 1) {
        throw new Exception($dataDecoded->message);
    }

    if (empty($dataDecoded) ||
        empty($dataDecoded->url) ||
        empty($dataDecoded->md5)) {
        throw new Exception("Unable to communicate with SiberianCMS server.");
    }

    $file_url = $dataDecoded->url . "&licensekey=";
    $md5 = $dataDecoded->md5;
}

/**
 * Class Requirements
 */
class Requirements
{

    /**
     * @var array
     */
    public static $_functions = [
        "exec",
    ];

    /**
     * @var array
     */
    public static $_extensions = [
        "SimpleXML",
        "pdo_mysql",
        "gd",
        "mbstring",
        "iconv",
        "curl",
        "openssl",
    ];

    /**
     * @var array
     */
    public static $_binaries = [
        "zip",
        "unzip",
        "wget",
    ];

    public static $_ini = [
        'max_execution_time' => 300,
        'max_input_time' => 300,
        'memory_limit' => 512,
        'post_max_size' => 100,
        'upload_max_filesize' => 100,
        'allow_url_fopen' => 1,
    ];

    /**
     * @var array
     */
    public static $_errors = [];

    /**
     * @throws Exception
     */
    public static function runTest()
    {
        self::testFunctions();
        self::testExtensions();
        self::testExec();
        self::testIni();

        if (count(self::$_errors) > 0) {
            throw new Exception(implode("<br />", [
                "Following requirements are missing: <br />",
                implode("<br />", self::$_errors),
                "...<br />"
            ]));
        }
    }

    public static function testIni()
    {

        $allIni = ini_get_all();
        foreach (self::$_ini as $ini => $minValue) {
            $value = preg_replace('/[^0-9]/', '', $allIni[$ini]['local_value']);
            if ($value < $minValue) {
                self::$_errors[] = "PHP ini `{$ini}` must be >= to `{$minValue}`";
            }
        }
    }

    /**
     *
     */
    public static function testFunctions()
    {
        foreach (self::$_functions as $function) {
            if (!function_exists($function)) {
                self::$_errors[] = "Please enable/add function: {$function}()";
            }
        }
    }

    /**
     *
     */
    public static function testExtensions()
    {
        foreach (self::$_extensions as $extension) {
            if (!extension_loaded($extension)) {
                self::$_errors[] = "Please enable/add extension: {$extension}";
            }
        }
    }

    /**
     *
     */
    public static function testExec()
    {
        if (function_exists("exec")) {
            foreach (self::$_binaries as $binary) {
                $result = exec("which {$binary}");
                if (empty($result)) {
                    self::$_errors[] = "Please enable/add binary: {$binary}";
                }
            }
            unset($result);
        } else {
            self::$_errors[] = "Please enable/add function: exec()";
        }

        if (OPENSSL_VERSION_NUMBER < 268439647) {
            self::$_errors[] = "Please update OpenSSL to 1.0.1+";
        }
    }
}

class Request
{
    /**
     * @var bool
     */
    public static $statusCode = false;

    /**
     * @param $endpoint
     * @param array $data
     * @param int $timeout
     * @return bool|string
     */
    public static function get($endpoint, $data = [], $timeout = 30)
    {
        $request = curl_init();
        if (!empty($data)) {
            // Handling pre-built uris with query
            if (strpos($endpoint, "?") === false) {
                $endpoint .= "?" . http_build_query($data);
            } else {
                $endpoint .= "&" . http_build_query($data);
            }
        }

        # Setting options
        curl_setopt($request, CURLOPT_URL, $endpoint);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($request, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($request, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);

        # Call
        $result = curl_exec($request);

        $status_code = curl_getinfo($request, CURLINFO_HTTP_CODE);

        # Save last status code
        self::$statusCode = $status_code;

        # Closing connection
        curl_close($request);

        return $result;
    }
}

// Checking dev domains!
function checkDomain($domain)
{
    if (strpos($domain, 'localhost') !== false) {
        return false;
    }
    if (preg_match('/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/', $domain) === 1) {
        return false;
    }
    return true;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>SIBERIAN - INSTALL</title>
    <link rel="icon"
          type="image/png"
          href="data:image/png;base64,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"/>

    <style media="screen" type="text/css">
        html, body {
            width: 100%;
            height: 100%;
            padding: 0;
            margin: 0;
            border: 0;
        }

        body {
            font-family: 'Roboto', sans-serif;
            color: #333333;
        }

        .container {
            position: relative;
            text-align: center;
            width: 700px;
            max-width: 100vw;
            margin: 0 auto;
            top: 50%;
            transform: translateY(-65%);
        }

        .form {
            background: #f7f7f7;
            padding: 0 50px 30px 50px;
            border-radius: 10px;
            box-shadow: 1px 1px 7px #a1a1a1;
            margin: 20px;
        }

        #alert_message {
            display: block;
            margin-top: 20px;
            color: #ffffff;
            background-color: #f53b38;
            padding: 10px;
            border-radius: 7px;
            font-weight: normal;
            box-shadow: 1px 1px 3px grey;
        }

        .licenseForm {
            margin-top: 10px;
            font-size: 1em;
        }

        .licenseForm input {
            font-family: 'Roboto', sans-serif;
            width: 300px;
            padding: 10px 15px;
            color: #555;
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 32px;
            transition: border-color ease-in-out .15s, box-shadow ease-in-out .15s;
            font-size: 16px;
            margin-bottom: 15px;
        }

        .licenseForm button,
        .licenseForm a {
            font-family: 'Roboto', sans-serif;
            display: inline-block;
            padding: 6px 12px;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            cursor: pointer;
            border: 1px solid transparent;
            border-radius: 32px;
            background-color: #0099C7;
            color: white;
            text-decoration: none;
            font-size: 20px;
            box-shadow: 1px 1px 1px #484848;
        }

        .licenseForm button:hover,
        .licenseForm a:hover {
            background-color: white;
            color: #0099C7;
            text-decoration: none;
            border: 1px solid lightgrey;
        }

        .legals {
            display: inline-block;
            margin: 0 auto;
            padding: 0 20px;
            line-height: 25px;
        }

        .legals a {
            color: #006381;
            text-decoration: none;
            text-transform: uppercase;
            font-size: 14px;
            cursor: pointer !important;
        }

    </style>
</head>
<body>
<div class="container">
    <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAANsAAAA5CAYAAAC/D17JAAAACXBIWXMAAAsTAAALEwEAmpwYAABDG2lUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSLvu78iIGlkPSJXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQiPz4KPHg6eG1wbWV0YSB4bWxuczp4PSJhZG9iZTpuczptZXRhLyIgeDp4bXB0az0iQWRvYmUgWE1QIENvcmUgNS42LWMwNjcgNzkuMTU3NzQ3LCAyMDE1LzAzLzMwLTIzOjQwOjQyICAgICAgICAiPgogICA8cmRmOlJERiB4bWxuczpyZGY9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMiPgogICAgICA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIgogICAgICAgICAgICB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iCiAgICAgICAgICAgIHhtbG5zOnhtcE1NPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvbW0vIgogICAgICAgICAgICB4bWxuczpzdFJlZj0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL3NUeXBlL1Jlc291cmNlUmVmIyIKICAgICAgICAgICAgeG1sbnM6c3RFdnQ9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZUV2ZW50IyIKICAgICAgICAgICAgeG1sbnM6ZGM9Imh0dHA6Ly9wdXJsLm9yZy9kYy9lbGVtZW50cy8xLjEvIgogICAgICAgICAgICB4bWxuczpwaG90b3Nob3A9Imh0dHA6Ly9ucy5hZG9iZS5jb20vcGhvdG9zaG9wLzEuMC8iCiAgICAgICAgICAgIHhtbG5zOnRpZmY9Imh0dHA6Ly9ucy5hZG9iZS5jb20vdGlmZi8xLjAvIgogICAgICAgICAgICB4bWxuczpleGlmPSJodHRwOi8vbnMuYWRvYmUuY29tL2V4aWYvMS4wLyI+CiAgICAgICAgIDx4bXA6Q3JlYXRvclRvb2w+QWRvYmUgUGhvdG9zaG9wIENDIDIwMTUgKE1hY2ludG9zaCk8L3htcDpDcmVhdG9yVG9vbD4KICAgICAgICAgPHhtcDpDcmVhdGVEYXRlPjIwMTYtMTItMjlUMTE6MTc6MzQrMDE6MDA8L3htcDpDcmVhdGVEYXRlPgogICAgICAgICA8eG1wOk1vZGlmeURhdGU+MjAxNi0xMi0yOVQxMToxOTowNCswMTowMDwveG1wOk1vZGlmeURhdGU+CiAgICAgICAgIDx4bXA6TWV0YWRhdGFEYXRlPjIwMTYtMTItMjlUMTE6MTk6MDQrMDE6MDA8L3htcDpNZXRhZGF0YURhdGU+CiAgICAgICAgIDx4bXBNTTpJbnN0YW5jZUlEPnhtcC5paWQ6ZDBhYTE3MjItOTUyMy00ZjM3LWI1YWMtYjZiMWZlZDNiNmZiPC94bXBNTTpJbnN0YW5jZUlEPgogICAgICAgICA8eG1wTU06RG9jdW1lbnRJRD5hZG9iZTpkb2NpZDpwaG90b3Nob3A6OWVmNWY3ZmYtMGUzZS0xMTdhLWE5ZTYtOTQzYjhjMmY3MzdiPC94bXBNTTpEb2N1bWVudElEPgogICAgICAgICA8eG1wTU06RGVyaXZlZEZyb20gcmRmOnBhcnNlVHlwZT0iUmVzb3VyY2UiPgogICAgICAgICAgICA8c3RSZWY6aW5zdGFuY2VJRD54bXAuaWlkOjQ2YjRiYzM2LTRlZTMtNDNiNi1hZWQ0LWNhYTM4M2I3MDVlMTwvc3RSZWY6aW5zdGFuY2VJRD4KICAgICAgICAgICAgPHN0UmVmOmRvY3VtZW50SUQ+eG1wLmRpZDo3NThhYjIwYi1mY2UxLTQwODQtYWI2Zi0zNTczNzkyMzYxNGY8L3N0UmVmOmRvY3VtZW50SUQ+CiAgICAgICAgICAgIDxzdFJlZjpvcmlnaW5hbERvY3VtZW50SUQ+eG1wLmRpZDpFNEU1MEM1QTJDQTAxMUU2OTVCRkU5RjAzNUI3QkMzNzwvc3RSZWY6b3JpZ2luYWxEb2N1bWVudElEPgogICAgICAgICA8L3htcE1NOkRlcml2ZWRGcm9tPgogICAgICAgICA8eG1wTU06T3JpZ2luYWxEb2N1bWVudElEPnhtcC5kaWQ6RTRFNTBDNUEyQ0EwMTFFNjk1QkZFOUYwMzVCN0JDMzc8L3htcE1NOk9yaWdpbmFsRG9jdW1lbnRJRD4KICAgICAgICAgPHhtcE1NOkhpc3Rvcnk+CiAgICAgICAgICAgIDxyZGY6U2VxPgogICAgICAgICAgICAgICA8cmRmOmxpIHJkZjpwYXJzZVR5cGU9IlJlc291cmNlIj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OmFjdGlvbj5zYXZlZDwvc3RFdnQ6YWN0aW9uPgogICAgICAgICAgICAgICAgICA8c3RFdnQ6aW5zdGFuY2VJRD54bXAuaWlkOmIzNjIxYTc3LTUyMjYtNDdkNi1hZTAyLWM2ZTZlNTU3MzQ3Yjwvc3RFdnQ6aW5zdGFuY2VJRD4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OndoZW4+MjAxNi0xMi0yOVQxMToxODo1NyswMTowMDwvc3RFdnQ6d2hlbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnNvZnR3YXJlQWdlbnQ+QWRvYmUgUGhvdG9zaG9wIENDIDIwMTUgKE1hY2ludG9zaCk8L3N0RXZ0OnNvZnR3YXJlQWdlbnQ+CiAgICAgICAgICAgICAgICAgIDxzdEV2dDpjaGFuZ2VkPi88L3N0RXZ0OmNoYW5nZWQ+CiAgICAgICAgICAgICAgIDwvcmRmOmxpPgogICAgICAgICAgICAgICA8cmRmOmxpIHJkZjpwYXJzZVR5cGU9IlJlc291cmNlIj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OmFjdGlvbj5jb252ZXJ0ZWQ8L3N0RXZ0OmFjdGlvbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnBhcmFtZXRlcnM+ZnJvbSBpbWFnZS9wbmcgdG8gYXBwbGljYXRpb24vdm5kLmFkb2JlLnBob3Rvc2hvcDwvc3RFdnQ6cGFyYW1ldGVycz4KICAgICAgICAgICAgICAgPC9yZGY6bGk+CiAgICAgICAgICAgICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0iUmVzb3VyY2UiPgogICAgICAgICAgICAgICAgICA8c3RFdnQ6YWN0aW9uPmRlcml2ZWQ8L3N0RXZ0OmFjdGlvbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnBhcmFtZXRlcnM+Y29udmVydGVkIGZyb20gaW1hZ2UvcG5nIHRvIGFwcGxpY2F0aW9uL3ZuZC5hZG9iZS5waG90b3Nob3A8L3N0RXZ0OnBhcmFtZXRlcnM+CiAgICAgICAgICAgICAgIDwvcmRmOmxpPgogICAgICAgICAgICAgICA8cmRmOmxpIHJkZjpwYXJzZVR5cGU9IlJlc291cmNlIj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OmFjdGlvbj5zYXZlZDwvc3RFdnQ6YWN0aW9uPgogICAgICAgICAgICAgICAgICA8c3RFdnQ6aW5zdGFuY2VJRD54bXAuaWlkOjc1OGFiMjBiLWZjZTEtNDA4NC1hYjZmLTM1NzM3OTIzNjE0Zjwvc3RFdnQ6aW5zdGFuY2VJRD4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OndoZW4+MjAxNi0xMi0yOVQxMToxODo1NyswMTowMDwvc3RFdnQ6d2hlbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnNvZnR3YXJlQWdlbnQ+QWRvYmUgUGhvdG9zaG9wIENDIDIwMTUgKE1hY2ludG9zaCk8L3N0RXZ0OnNvZnR3YXJlQWdlbnQ+CiAgICAgICAgICAgICAgICAgIDxzdEV2dDpjaGFuZ2VkPi88L3N0RXZ0OmNoYW5nZWQ+CiAgICAgICAgICAgICAgIDwvcmRmOmxpPgogICAgICAgICAgICAgICA8cmRmOmxpIHJkZjpwYXJzZVR5cGU9IlJlc291cmNlIj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OmFjdGlvbj5zYXZlZDwvc3RFdnQ6YWN0aW9uPgogICAgICAgICAgICAgICAgICA8c3RFdnQ6aW5zdGFuY2VJRD54bXAuaWlkOjQ2YjRiYzM2LTRlZTMtNDNiNi1hZWQ0LWNhYTM4M2I3MDVlMTwvc3RFdnQ6aW5zdGFuY2VJRD4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OndoZW4+MjAxNi0xMi0yOVQxMToxOTowNCswMTowMDwvc3RFdnQ6d2hlbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnNvZnR3YXJlQWdlbnQ+QWRvYmUgUGhvdG9zaG9wIENDIDIwMTUgKE1hY2ludG9zaCk8L3N0RXZ0OnNvZnR3YXJlQWdlbnQ+CiAgICAgICAgICAgICAgICAgIDxzdEV2dDpjaGFuZ2VkPi88L3N0RXZ0OmNoYW5nZWQ+CiAgICAgICAgICAgICAgIDwvcmRmOmxpPgogICAgICAgICAgICAgICA8cmRmOmxpIHJkZjpwYXJzZVR5cGU9IlJlc291cmNlIj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OmFjdGlvbj5jb252ZXJ0ZWQ8L3N0RXZ0OmFjdGlvbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnBhcmFtZXRlcnM+ZnJvbSBhcHBsaWNhdGlvbi92bmQuYWRvYmUucGhvdG9zaG9wIHRvIGltYWdlL3BuZzwvc3RFdnQ6cGFyYW1ldGVycz4KICAgICAgICAgICAgICAgPC9yZGY6bGk+CiAgICAgICAgICAgICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0iUmVzb3VyY2UiPgogICAgICAgICAgICAgICAgICA8c3RFdnQ6YWN0aW9uPmRlcml2ZWQ8L3N0RXZ0OmFjdGlvbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnBhcmFtZXRlcnM+Y29udmVydGVkIGZyb20gYXBwbGljYXRpb24vdm5kLmFkb2JlLnBob3Rvc2hvcCB0byBpbWFnZS9wbmc8L3N0RXZ0OnBhcmFtZXRlcnM+CiAgICAgICAgICAgICAgIDwvcmRmOmxpPgogICAgICAgICAgICAgICA8cmRmOmxpIHJkZjpwYXJzZVR5cGU9IlJlc291cmNlIj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OmFjdGlvbj5zYXZlZDwvc3RFdnQ6YWN0aW9uPgogICAgICAgICAgICAgICAgICA8c3RFdnQ6aW5zdGFuY2VJRD54bXAuaWlkOmQwYWExNzIyLTk1MjMtNGYzNy1iNWFjLWI2YjFmZWQzYjZmYjwvc3RFdnQ6aW5zdGFuY2VJRD4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OndoZW4+MjAxNi0xMi0yOVQxMToxOTowNCswMTowMDwvc3RFdnQ6d2hlbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0OnNvZnR3YXJlQWdlbnQ+QWRvYmUgUGhvdG9zaG9wIENDIDIwMTUgKE1hY2ludG9zaCk8L3N0RXZ0OnNvZnR3YXJlQWdlbnQ+CiAgICAgICAgICAgICAgICAgIDxzdEV2dDpjaGFuZ2VkPi88L3N0RXZ0OmNoYW5nZWQ+CiAgICAgICAgICAgICAgIDwvcmRmOmxpPgogICAgICAgICAgICA8L3JkZjpTZXE+CiAgICAgICAgIDwveG1wTU06SGlzdG9yeT4KICAgICAgICAgPGRjOmZvcm1hdD5pbWFnZS9wbmc8L2RjOmZvcm1hdD4KICAgICAgICAgPHBob3Rvc2hvcDpDb2xvck1vZGU+MzwvcGhvdG9zaG9wOkNvbG9yTW9kZT4KICAgICAgICAgPHRpZmY6T3JpZW50YXRpb24+MTwvdGlmZjpPcmllbnRhdGlvbj4KICAgICAgICAgPHRpZmY6WFJlc29sdXRpb24+NzIwMDAwLzEwMDAwPC90aWZmOlhSZXNvbHV0aW9uPgogICAgICAgICA8dGlmZjpZUmVzb2x1dGlvbj43MjAwMDAvMTAwMDA8L3RpZmY6WVJlc29sdXRpb24+CiAgICAgICAgIDx0aWZmOlJlc29sdXRpb25Vbml0PjI8L3RpZmY6UmVzb2x1dGlvblVuaXQ+CiAgICAgICAgIDxleGlmOkNvbG9yU3BhY2U+NjU1MzU8L2V4aWY6Q29sb3JTcGFjZT4KICAgICAgICAgPGV4aWY6UGl4ZWxYRGltZW5zaW9uPjIxOTwvZXhpZjpQaXhlbFhEaW1lbnNpb24+CiAgICAgICAgIDxleGlmOlBpeGVsWURpbWVuc2lvbj41NzwvZXhpZjpQaXhlbFlEaW1lbnNpb24+CiAgICAgIDwvcmRmOkRlc2NyaXB0aW9uPgogICA8L3JkZjpSREY+CjwveDp4bXBtZXRhPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAg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">

    <div id="downloader"
         class="form">
        <br>
        <div class="licenseForm">
            <form id="installForm" method="post" action="/">
                <input type="hidden" name="sae" value="1">
                <button id="install_button" type="submit">Download &amp; Install</button>
                <?php if (!checkDomain($_SERVER["HTTP_HOST"])): ?>
                    <div class="note_information" style="color:#c20000;">
                        <strong>It looks like you are installing on a local domain or IP, please install from your
                            domain
                            name.</strong>
                    </div>
                <?php endif; ?>
                <div style="margin-top: 32px;">
                    <a style="padding: 4px 8px; font-size: 16px;"
                       href="/index.php?phpinfo=1"
                       target="_blank">check phpinfo</a>
                    &nbsp;&nbsp;&nbsp;
                    <a style="padding: 4px 8px; font-size: 16px; background-color: #ff9019 !important; color: white !important;"
                       href="https://doc.siberiancms.com/siberiancms-admins-guide/"
                       target="_blank">siberian admin guide!</a>
                </div>
            </form>
        </div>
        <?php
        if (!empty($errorMessage)) {
            echo "<h4 id=\"alert_message\">" . $errorMessage . "</h4>";
        }
        ?>
        <h4 id="loaderMessage">Downloading...</h4>
        <div id="loader">
            <div class="square"></div>
            <div class="square"></div>
            <div class="square last"></div>
            <div class="square clear"></div>
            <div class="square"></div>
            <div class="square last"></div>
            <div class="square clear"></div>
            <div class="square "></div>
            <div class="square last"></div>
        </div>
    </div>


    <div class="legals">
        <a target="_blank" href="https://xtraball.com">Xtraball</a>&nbsp;-
        <a target="_blank" href="https://www.siberiancms.com">SiberianCMS</a>&nbsp;-
        <a target="_blank" href="https://extensions.siberiancms.com">Marketplace</a><br />
        <a target="_blank" href="https://doc.siberiancms.com/">Documentation</a>&nbsp;-
        <a target="_blank" href="https://github.com/Xtraball/Siberian">Github</a>&nbsp;-
        <a target="_blank" href="https://www.siberiancms.com/community">Community</a>
    </div>
</div>


<script>
    (function () {
        var submitButton = document.getElementById("install_button");
        var loader = document.getElementById("loader");
        var loaderMessage = document.getElementById("loaderMessage");
        var installForm = document.getElementById("installForm");
        var errorMessage = document.getElementById("alert_message");
        submitButton.addEventListener("click", function (e) {
            e.stopPropagation();
            e.preventDefault();
            loader.style.display = "block";
            loaderMessage.style.display = "block";
            submitButton.style.display = "none";
            if (errorMessage) {
                errorMessage.style.display = "none";
            }
            setTimeout(function () {
                installForm.submit();
            }, 100);
        });
    })();
</script>
<style media="screen" type="text/css">
    @-webkit-keyframes enter {
        0% {
            opacity: 0;
            top: -10px;
        }
        5% {
            opacity: 1;
            top: 0;
        }
        50.9% {
            opacity: 1;
            top: 0;
        }
        55.9% {
            opacity: 0;
            top: 10px;
        }
    }

    @keyframes enter {
        0% {
            opacity: 0;
            top: -10px;
        }
        5% {
            opacity: 1;
            top: 0;
        }
        50.9% {
            opacity: 1;
            top: 0;
        }
        55.9% {
            opacity: 0;
            top: 10px;
        }
    }

    @-moz-keyframes enter {
        0% {
            opacity: 0;
            top: -10px;
        }
        5% {
            opacity: 1;
            top: 0;
        }
        50.9% {
            opacity: 1;
            top: 0;
        }
        55.9% {
            opacity: 0;
            top: 10px;
        }
    }

    #loaderMessage {
        display: none;
    }

    #loader {
        display: none;
        position: relative;
        left: 50%;
        transform: translate(-50%, 0);
        width: 55px;
        height: 70px;
    }

    .square {
        background: #0099C7;
        width: 15px;
        height: 15px;
        float: left;
        top: -10px;
        margin-right: 5px;
        margin-top: 5px;
        position: relative;
        opacity: 0;
        border-radius: 20px;
        -webkit-animation: enter 6s infinite;
        animation: enter 6s infinite;
    }

    .enter {
        top: 0;
        opacity: 1;
    }

    .square:nth-child(1) {
        -webkit-animation-delay: 1.8s;
        -moz-animation-delay: 1.8s;
        animation-delay: 1.8s;
    }

    .square:nth-child(2) {
        -webkit-animation-delay: 2.1s;
        -moz-animation-delay: 2.1s;
        animation-delay: 2.1s;
    }

    .square:nth-child(3) {
        -webkit-animation-delay: 2.4s;
        -moz-animation-delay: 2.4s;
        animation-delay: 2.4s;
        background: #ff9019;
    }

    .square:nth-child(4) {
        -webkit-animation-delay: 0.9s;
        -moz-animation-delay: 0.9s;
        animation-delay: 0.9s;
    }

    .square:nth-child(5) {
        -webkit-animation-delay: 1.2s;
        -moz-animation-delay: 1.2s;
        animation-delay: 1.2s;
    }

    .square:nth-child(6) {
        -webkit-animation-delay: 1.5s;
        -moz-animation-delay: 1.5s;
        animation-delay: 1.5s;
    }

    .square:nth-child(8) {
        -webkit-animation-delay: 0.3s;
        -moz-animation-delay: 0.3s;
        animation-delay: 0.3s;
    }

    .square:nth-child(9) {
        -webkit-animation-delay: 0.6s;
        -moz-animation-delay: 0.6s;
        animation-delay: 0.6s;
    }

    .clear {
        clear: both;
    }

    .last {
        margin-right: 0;
    }

    .note_information {
        margin-top: 10px;
        font-size: 12px;
    }

</style>
</body>
</html>

