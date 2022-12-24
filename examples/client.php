<?php namespace Phiws;

use Phiws\Client as C;

require 'bootstrap.php';

$blocking = true;

//Logger::defaultMinLevel('info');
$logger = new Loggers\File(__DIR__.'/client.log');
$logger->echoMode(true);

///*
BaseTunnel::globalPlugins($tpi = new Plugins\Testing);
//$tpi->messageSize = 200000;
$tpi->outputRate = 300;
//*/

$client = new Client;
$client->logger($logger);
$blocking and $client->plugins()->add(new Plugins\BlockingServer);
//$client->extensions()->add((new Extensions\PerMessageDeflate));

$addr = new ServerAddress('127.0.0.1', $blocking ? 81 : 8888);
$addr->path('/examples/phiwsjs.php');
$client->connect($addr);
$client->queuePing();
$client->queueTextData(mt_rand().uniqid());

$client->loopWait(250);
$client->loop();
$client->gracefulDisconnectAndWait();

//$client = ServerClient::forOutput($_SERVER);
//$client->logger($logger);
//$client->handshake();
