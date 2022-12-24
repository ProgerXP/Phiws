<?php namespace Phiws;

use Phiws\ServerClient as SC;
use Phiws\BaseTunnel as BT;

require 'bootstrap.php';

Logger::defaultMinLevel('info');
$logger = new Loggers\File(__DIR__.'/sse.log');

$pi = new Plugins\ServerSentEvents(8889);
$pi->server()->logger($logger);

$msg = [
  new Plugins\SSEvent('RAND '.uniqid()),
  new Plugins\SSEvent('RAND '.uniqid(), 'at'),
];

$pi->onTick(function () use ($pi, &$msg) {
  //mt_rand(0, 3) or exit;
  //mt_rand(0, 3) or $pi->stop();
  if ($m = array_shift($msg)) {
    return $m;
  } else {
    $pi->stop();
  }
});
$pi->loopWait(500);
$pi->ssePrefixWithEventType(true);
$pi->stopSseEvent('');
$pi->startSSE();
