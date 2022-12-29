<?php
declare(strict_types=1);

use SimulatorImport\Application;

require __DIR__ . '/../vendor/autoload.php';

if ($argc < 6) {
    die("Мало параметров\nphp script.php http://example/server_endpoint.php type login password /pathtofile1.xml /pathtofile2.xml");
}
$params = array_slice($argv, 1);
$url = $params[0];
$type = $params[1];
$login = $params[2];
$password = $params[3];
$filelist = array_slice($argv, 5);

(new Application($url, $type, $login, $password, [...$filelist]))->run();
