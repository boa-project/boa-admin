<?php

/*
 * Define security based on the machine name, the idea is that this api will be accessed as a service
 * from the same server, mainly by the search api
 */
$apiusr = md5(gethostname());
$apipwd = "";

/* 
 * Verify basic authentication was provided
 */

if (!isset($_SERVER['PHP_AUTH_USER'])
    || $_SERVER['PHP_AUTH_USER'] != $apiusr
    || (isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '') != $apipwd) {
    header('WWW-Authenticate: Basic realm="BoA API Realm"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'You are not authorized to access this API.';
    exit;
}

/*
 * Include basic configuration files required to start BoA app
 */
include_once("base.conf.php");
include_once("boa/src/Core/Utils/polyfills.php"); //ToDo: Is this the rigth way to do this?

use BoA\Core\Http\ApiRouter;

ApiRouter::init();

$router = new ApiRouter();
$router->run();
