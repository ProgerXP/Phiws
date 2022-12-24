<?php namespace Phiws;

use Phiws\ServerClient as SC;
use Phiws\BaseTunnel as BT;

require 'bootstrap.php';

class ServerPlugin extends Plugin {
  function randomStr() {
    $res = '';
    $resLen = mt_rand(100, 2000) * 100;

    while (strlen($res) < $resLen) {
      $blockCount = mt_rand(10, 50);

      for ($blockI = 0; $blockI < $blockCount; $blockI++) {
        $ch = mt_rand(0x61, 0x7A);
        $res .= str_repeat(chr($ch), mt_rand(10, 50));
      }
    }

    return $res;
  }

  function events() {
    return ['serverClientConnected', 'processFrame', 'bufferedFrameComplete'];
  }

  function serverClientConnected(ServerClient $client) {
    return;
    for ($i = 1; $i <= 50; $i++) {
      $client->sendTextData('RANDOM ['.$this->randomStr().']');
      usleep(100 * 1000);
    }
  }

  function processFrame(BaseTunnel $cx, Frame &$frame, &$processed) {
    $part = $cx->readingPartial();

    if ($part and $frame instanceof DataFrame and $cx->isOperational()) {
      $percent = ($part->nextOffset / $part->header->payloadLength) * 100;
      $msg = ['part, size '.number_format($part->nextOffset).' ('.($percent).'%)'];

      $size = $frame->applicationData() ? $frame->applicationData()->remainingLength() : 0;
      //$size and $msg[] = $frame->applicationData()->read(20);
      //$size and $frame->applicationData()->rewind();

      $msg = join(" | ", $msg);
      $cx->log("{{{   $msg");
      $cx->sendTextData($msg."\n  filler: ".$this->randomStr());
      $cx->log("}}}   $msg");
    }
  }

  function bufferedFrameComplete(BaseTunnel $cx, DataSource $appData = null, DataSource $extData = null) {
    if (!$appData) {
      return $cx->sendTextData('got only extension data, no application data');
    }

    $length = 0;
    $head = null;
    $hash = hash_init('sha256');

    do {
      $chunk = $appData->read(65536);
      $length += strlen($chunk);
      isset($head) or $head = substr($chunk, 0, 50);
      hash_update($hash, $chunk);
    } while (strlen($chunk));

    $hash = hash_final($hash);

    $header = number_format($length);

    if ($length !== $remaining = $appData->remainingLength()) {
      $header .= " (".number_format($remaining)." on wire)";
    }

    $msg = [
      "== RECV $header ==",
      "SHA-256 = $hash",
      "[$head]",
    ];

    $cx->log("\n".join("\n", $msg)."\n{{{");
    $cx->sendTextData(join("\n  ", $msg)."\n".$this->randomStr());
    $cx->log("}}}   $header");
  }
}

Logger::defaultMinLevel('info');
$logger = new Loggers\File(__DIR__.'/phiwsjs.log');

$picker = new Plugins\DataProcessorPicker;
$picker->proc(DataProcessors\BufferAndTrigger::class);

BaseTunnel::globalPlugins([]);
//BaseTunnel::globalPlugins(new ServerPlugin);
//BaseTunnel::globalPlugins($picker);
BaseTunnel::globalPlugins($spi = new Plugins\Statistics);
$spi->logLevel('warn');

///*
BaseTunnel::globalPlugins($tpi = new Plugins\Testing);
$tpi->outputRate = 1000;
$tpi->outputTimes = 0;
//$tpi->replyRate = 0;
$tpi->messageSize = 200000;
//*/

if (isset($argv)) {
  $server = new Server(8888);
  $server->logger($logger);
  $server->plugins()->add(new Plugins\GarbageCollector);
  $server->extensions()->add((new Extensions\PerMessageDeflate)
    ->minSizeToCompress(100 * 1000));
  $logger->echoMode(true);
  $server->start();
  $server->loopWait(250);
  $server->loop();
} else {
  $client = ServerClient::forOutput($_SERVER);
  $client->logger($logger);
  $client->plugins()->add($bpi = new Plugins\BlockingServer);
  $client->extensions()->add((new Extensions\PerMessageDeflate)
    ->minSizeToCompress(50 * 1000));

  $bpi->ignoreIncoming(true);
  //$client->plugins()->add($ppi = new Plugins\Ping);
  //$ppi->interval(1)->justPong(true);

  $client->handshake();

  //$h = fopen('php://input','rb');
  //rewind($h);
  //$client->log(var_export(fread($h, 100), true));
  //$client->log(var_export(feof($h), true));

  $client->queueTextData('{"comment":"<< TEST FROM SRV >>"}');
  $client->loopWait(1000);
  $client->loop();
}
