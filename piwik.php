<?php

/**
 * Piwik - free/libre analytics platform
 * Piwik Proxy Hide URL
 *
 * @link http://piwik.org/faq/how-to/#faq_132
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
// -----
// Important: read the instructions in README.md or at:
// https://github.com/piwik/piwik/tree/master/misc/proxy-hide-piwik-url#piwik-proxy-hide-url
// -----

require '../../config/config.inc.php';

// Edit the line below, and replace http://your-piwik-domain.example.org/piwik/
// with your Piwik URL ending with a slash.
// This URL will never be revealed to visitors or search engines.
$PIWIK_URL = ((bool) Configuration::get('PIWIK_CRHTTPS') ? 'https://' : 'http://') . Configuration::get('PIWIK_HOST');

// Edit the line below, and replace xyz by the token_auth for the user "UserTrackingAPI"
// which you created when you followed instructions above.
$TOKEN_AUTH = Configuration::get('PIWIK_TOKEN_AUTH');

// GET http auth if set
$httpauth = "";
$httpauth_usr = Configuration::get('PIWIK_PAUTHUSR');
$httpauth_pwd = Configuration::get('PIWIK_PAUTHPWD');
if ((!empty($httpauth_usr) && !is_null($httpauth_usr) && $httpauth_usr !== false) && (!empty($httpauth_pwd) && !is_null($httpauth_pwd) && $httpauth_pwd !== false)) {
    $httpauth = "Authorization: Basic " . base64_encode("$httpauth_usr:$httpauth_pwd") . "\r\n";
}

// Maximum time, in seconds, to wait for the Piwik server to return the 1*1 GIF
$timeout = 5;

// Create the default http context options
$http_options = array(
    'http' => array(
        'user_agent' => (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
        'method' => "GET",
        'timeout' => $timeout,
        'header' => sprintf("Accept-Language: %s\r\n", @str_replace(array("\n", "\t", "\r"), "", $_SERVER['HTTP_ACCEPT_LANGUAGE'])) .
                    $httpauth,
    )
);
$http_context = stream_context_create($http_options);

function sendHeader($header, $replace = true) {
    headers_sent() || header($header, $replace);
}

function arrayValue($array, $key, $value = null) {
    if (!empty($array[$key])) {
        $value = $array[$key];
    }
    return $value;
}

// DO NOT MODIFY BELOW
// ---------------------------
// 1) PIWIK.JS PROXY: No _GET parameter, we serve the JS file
if (empty($_GET)) {
    $modifiedSince = false;
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $modifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'];
        // strip any trailing data appended to header
        if (false !== ($semicolon = strpos($modifiedSince, ';'))) {
            $modifiedSince = strtotime(substr($modifiedSince, 0, $semicolon));
        }
    }
    // Re-download the piwik.js once a day maximum
    $lastModified = time() - 86400;

    // set HTTP response headers
    sendHeader('Vary: Accept-Encoding');

    // Returns 304 if not modified since
    if (!empty($modifiedSince) && $modifiedSince < $lastModified) {
        sendHeader(sprintf("%s 304 Not Modified", $_SERVER['SERVER_PROTOCOL']));
    } else {
        sendHeader('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        sendHeader('Content-Type: application/javascript; charset=UTF-8');
        if ($piwikJs = file_get_contents($PIWIK_URL . 'piwik.js', false, $http_context)) {
            echo $piwikJs;
        } else {
            sendHeader($_SERVER['SERVER_PROTOCOL'] . '505 Internal server error');
        }
    }
    exit;
}

@ini_set('magic_quotes_runtime', 0);

// 2) PIWIK.PHP PROXY: GET parameters found, this is a tracking request, we redirect it to Piwik
$url = sprintf("%spiwik.php?cip=%s&token_auth=%s&", $PIWIK_URL, getVisitIp(), $TOKEN_AUTH);

foreach ($_GET as $key => $value) {
    $url .= urlencode($key) . '=' . urlencode($value) . '&';
}
sendHeader("Content-Type: image/gif");
echo file_get_contents($url, false, $http_context);

function getVisitIp() {
    $matchIp = '/^([0-9]{1,3}\.){3}[0-9]{1,3}$/';
    $ipKeys = array(
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
    );
    foreach ($ipKeys as $ipKey) {
        if (isset($_SERVER[$ipKey]) && preg_match($matchIp, $_SERVER[$ipKey])) {
            return $_SERVER[$ipKey];
        }
    }
    return arrayValue($_SERVER, 'REMOTE_ADDR');
}
