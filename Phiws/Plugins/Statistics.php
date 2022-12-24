<?php namespace Phiws\Plugins;

use Phiws\Utils;

class Statistics extends \Phiws\StatefulPlugin {
  protected $logEach = 10;
  protected $logLevel = 0;
  protected $logResStats = true;
  protected $garbageCollect = false;

  protected $lastLog;
  protected $longestIteration;

  // Null or time().
  function lastLogTime() {
    return $this->lastLog;
  }

  function logEach($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function logLevel($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function logResStats($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  function garbageCollect($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  function reset() {
    $this->longestIteration = 0;
  }

  function events() {
    return array_merge(parent::events(), ['loopTick', '-readMessageBuffer', 'disconnected']);
  }

  function loopTick(\Phiws\BaseObject $cx, $maxWait, $iterDuration) {
    $this->longestIteration = max($this->longestIteration, $iterDuration);
  }

  function readMessageBuffer(\Phiws\BaseTunnel $cx, &$buffer, $keptBuffer, $handle) {
    if ($this->lastLog + $this->logEach <= time()) {
      $this->lastLog = time();
      $this->logStats($cx);
    }
  }

  function disconnected(\Phiws\BaseTunnel $cx, \Phiws\StatusCode $code = null) {
    $this->logStats($cx);
  }

  function logStats(\Phiws\BaseTunnel $cx) {
    if (!$cx->logger()->logs($this->logLevel)) {
      return;
    }

    $writingState = $cx->writingState();
    $readingState = $cx->readingState();

    $flags = [];

    if ($cx->isConnected()) {
      $flags[] = $this->formatTime('up since', $cx->connectedSince());
    } elseif ($time = $cx->connectedSince()) {
      $clean = $cx->isCleanClose() ? 'clean' : 'unclean';
      $flags[] = $this->formatTime("down ($clean); was up since", $cx->connectedSince());
    } else {
      $flags[] = 'not connected';
    }

    if ($cx instanceof \Phiws\ServerClient and $cx->server() and 
        1 < $count = count($cx->server())) {
      $flags[] = "$count clients";
    }

    $flags[] = 'read '.number_format($readingState->bytesOnWire);
    $flags[] = 'sent '.number_format($writingState->bytesOnWire);

    if ($this->logResStats) {
      if (function_exists('get_resources')) {
        $flags[] = number_format(count(get_resources())).' handles';
      }

      $flags[] = sprintf('%dM mem (%dM peak)', memory_get_usage() / 1024 / 1024,
        memory_get_peak_usage() / 1024 / 1024);

      if (function_exists('gc_mem_caches')) {
        $flags[] = (gc_mem_caches() / 1024).'K GC mem';
      }
    }

    if ($this->garbageCollect) {
      $flags[] = 'GC: '.gc_collect_cycles();
    }

    if ($this->longestIteration > 10) {
      $flags[] = "longest loop ".round($this->longestIteration)." ms";
    }

    $flags[] = $this->formatTimeOf('ping sent', $writingState->pingFrame);
    $flags[] = $this->formatTimeOf('pong sent', $writingState->pongFrame);
    $flags[] = $this->formatTimeOf('pong received', $readingState->pongFrame);
    $flags[] = $this->formatTimeOf('ping received', $readingState->pingFrame);

    $flags[] = $this->formatCloseFrame('sent', $writingState->closeFrame);
    $flags[] = $this->formatCloseFrame('received', $readingState->closeFrame);

    $cx->log("statistics: ".join(' | ', array_filter($flags)), null, $this->logLevel);
  }

  function formatCloseFrame($msg, \Phiws\Frames\Close $frame = null) {
    if ($frame) {
      $code = $frame->statusCode() ? ' ('.$frame->statusCode()->code().')' : '';
      return $this->formatTimeOf("close$code $msg", $frame);
    }
  }

  function formatTimeOf($msg, \Phiws\Frame $frame = null) {
    if ($frame and $time = $frame->timeSent()) {
      return $this->formatTime($msg, $time);
    }
  }

  function formatTime($msg, $time) {
    $ago = microtime(true) - $time;

    if ($ago > 180) {
      $time = 'on '.date('d.m H:i:s', $time);
    } else {
      $time = number_format($ago, 3).' s ago';
    }

    $res = str_replace('#', $time, $msg, $count);
    $count or $res .= " $time";
    return $res;
  }
}
