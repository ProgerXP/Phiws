<?php namespace Phiws\Plugins;

use Phiws\BaseTunnel as BT;
use Phiws\DataFrame;
use Phiws\DataSource;

class Testing extends \Phiws\Plugin {
  const HASH_ALGO = 'sha256';

  // Send new packets each $outputRate ms, at most $outputTimes (0 to disable) 
  // per connection duration.
  public $outputRate = 100;
  public $outputTimes = 60000;

  public $replyToParts = true;

  // Send replies to each $replyRate incoming packets (0 to disable).
  public $replyRate = 1;

  public $replyMatchLevel = 1;

  // A single value (fixed size) or [$min, $max] for random. 
  public $messageSize = [10000, 1000000];

  protected $lastOutput;
  protected $sessionOutputTimes;
  protected $replyCount = 0;

  // Array of arrays with keys 'hash' (SHA-256), 'size'.
  protected $sentMessages = [];

  function isOutputting() {
    return $this->outputRate > 0 and $this->outputTimes > 0;
  }

  function isReplying() {
    return $this->replyRate > 0;
  }

  protected function reset() {
    $this->lastOutput = null;
    $this->sessionOutputTimes = 0;
    $this->replyCount = 0;
    $this->sentMessages = [];
  }

  function events() {
    $events = ['readMessageBuffer', 'processFrame'];

    if ($this->isOutputting()) {
      $events[] = 'loopTick';
    }

    $events[] = 'pickProcessorFor';
    $events[] = 'bufferedFrameComplete';

    return $events;
  }

  function readMessageBuffer(BT $cx, &$buffer, $keptBuffer, $handle) {
    $cx->log('---------------------------- Testing Plugin ----------------------------');
  }

  function loopTick(\Phiws\BaseObject $cx, $maxWait, $iterDuration) {
    $now = microtime(true) * 1000;

    if ($this->lastOutput + $this->outputRate <= $now) {
      if (++$this->sessionOutputTimes <= $this->outputTimes) {
        $this->lastOutput = $now;
        $this->sendFiller($cx, 'R! generating output', true);
      } else {
        $cx->gracefulDisconnect();
      }
    }
  }

  function processFrame(BT $cx, \Phiws\Frame &$frame, &$processed) {
    if ($frame instanceof DataFrame and !$frame->isComplete()) {
      $reading = $cx->readingPartial();
      $offset = $reading ? $reading->nextOffset : '(end)';
      $perc = $reading ? $offset / $reading->header->payloadLength * 100 : 100;

      if ($frame->applicationData()) {
        $size = number_format( $frame->applicationData()->size() );
      } else {
        $size = 0;
      }

      $msg = [
        "recv part size:$size, next offset:$offset, done:$perc%",
        "part:  {$frame->describe()}",
      ];

      $reading and $msg[] = "start: {$reading->firstFrame->describe()}";
      $this->log($cx, join("\n", $msg));

      if ($this->replyToParts) {
        $this->send($cx, join("\n", $msg));
      }
    }
  }

  function pickProcessorFor(BT $cx, &$res, DataFrame $frame) {
    $res = new \Phiws\DataProcessors\BufferAndTrigger($frame, $cx);
    return false;
  }

  function bufferedFrameComplete(BT $cx, DataSource $applicationData = null, DataSource $extensionData = null) {
    if (!$applicationData) {
      return $this->log("recv only extension data, no application data");
    }

    $info = $this->digest($applicationData);
    $size = number_format($info['size']);
    $wire = number_format($info['sizeOnWire']);
    $head = substr($info['head'], 0, 50);
    $msg = "recv complete size:$size, on wire:$wire, hash:$info[hash], head:[$head]";

    $this->log($cx, $msg);

    if (!strncmp($head, 'R!', 2) and $this->isReplying()
        and ++$this->replyCount % $this->replyRate === 0) {
      $this->sendFiller($cx, "C! $msg");
    }

    $regexp = '~^C! recv complete size:([\d,]+), on wire:([\d,]+), hash:(\w+)~u';

    if (preg_match($regexp, $info['head'], $match)) {
      $this->compareReplyWithSend($cx, $match);
    }
  }

  protected function compareReplyWithSend($cx, array $match) {
    list(, $fmtSize, , $hash) = $match;
    $size = (int) str_replace(',', '', $fmtSize);
    $msg = [];

    while ($sent = array_shift($this->sentMessages)) {
      $matches = ($size === $sent['size'] and strtolower($hash) === $sent['hash']);

      if ($matches) {
        $msg[] = "recv reply ok for size:$fmtSize, hash:$hash";
        break;
      } else {
        $msg[] = 
          "recv reply has lost data; ".
          "recv: size:$fmtSize, hash:$hash; ".
          "sent size:".number_format($sent['size']).", hash:$sent[hash]";
      } 
    }

    if (!$msg) {
      $msg[] = "recv reply for not sent data for size:$fmtSize, hash:$hash";
    }

    $msg = join("\n", $msg);
    $this->log($cx, $msg, null, $matches + $this->replyMatchLevel);
    $this->isReplying() and $this->send($cx, $msg);
  }

  function sendFiller(BT $cx, $msg, $track = false) {
    $filler = $this->randomStr();
    return $this->send($cx, $msg."\nfiller [".strlen($filler)."]\n$filler", $track);
  }

  function send(BT $cx, $msg, $track = false) {
    if ($track) {
      $size = strlen($msg);
      $hash = hash(static::HASH_ALGO, $msg);
      $this->sentMessages[] = compact('hash', 'size');
      $this->log($cx, "sent [$size] hash:$hash", null, $this->replyMatchLevel);
    }

    $cx->queueTextData($msg);
  }

  function log($cx, $msg, $e = null, $level = 0) {
    $cx->log("    ** $msg", $e, $level);
  }

  protected function digest(DataSource $data) {
    $size = 0;
    $sizeOnWire = $data->size();
    $head = null;
    $hash = hash_init(static::HASH_ALGO);

    $data->readChunks(65536, function ($chunk) use (&$size, &$head, $hash) {
      $size += strlen($chunk);
      isset($head) or $head = $chunk;
      hash_update($hash, $chunk);
    });

    $hash = hash_final($hash);
    return compact('size', 'sizeOnWire', 'head', 'hash');
  }

  function randomStr() {
    if (is_array($this->messageSize)) {
      list($min, $max) = $this->messageSize;
      $resLen = mt_rand($min, $max);
    } else {
      $resLen = $this->messageSize;
    }

    $res = '';

    while (!isset($res[$resLen])) {
      $res .= str_repeat(chr(mt_rand(0x61, 0x7A)), mt_rand(1, 1000));
    }

    return substr($res, 0, $resLen);
  }
}
