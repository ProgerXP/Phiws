<?php namespace Phiws\Plugins;

use Phiws\Frame;
use Phiws\Utils;

// $client->plugins()->add(new MaxPayloadLength);
class MaxPayloadLength extends \Phiws\Extension {
  const ID = '_MaxPayloadLength';

  protected $inboundLimit;
  protected $outboundLimit;
  // Values: before, after, error.
  protected $fragmentMode = 'before';

  // Null/0 disable check in this direction.
  // inboundLimit closes connection if frame's payload length is over this size.
  // outboundLimit fragments outgoing frames to be under that size.
  function inboundLimit($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'float');
  }

  function outboundLimit($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'float');
  }

  // Modes:
  // - before - fragment frames before they enter the pipeline
  // - after - fragment immediate frames to be sent
  // - error - close connection and throw if trying to sent big frames (don't try to fragment)
  //
  // For example, 'before' + deflate extension compresses frames of no more than $outboundLimit
  // size each, 'after' + deflate - compresses entire big frame into a single frame
  // and then fragments single resulting stream into smaller frames (this is allowed
  // by RFC 7692). 'error' will let deflate compress entire big frame but if result
  // is too long - it will fail the connection.
  function fragmentMode() {
    return $this->fragmentMode;
  }

  function preFragment() {
    $this->fragmentMode = 'before';
    return $this;
  }

  function postFragment() {
    $this->fragmentMode = 'after';
    return $this;
  }

  function errorOnFragment() {
    $this->fragmentMode = 'error';
    return $this;
  }

  /**
   * Plugin
   */

  function events() {
    $events = [];

    $events[] = 'resetContext';
    $this->inboundLimit > 0 and $events[] = 'splitPayload';

    if ($this->outboundLimit > 0 and $this->fragmentMode === 'error') {
      $events[] = 'sendRawFrames';
    }

    return $events;
  }

  function resetContext(\Phiws\BaseObject $cx) {
    if ($cx instanceof \Phiws\BaseTunnel) {
      $cx->extensions()->add($this);
    }
  }

  function splitPayload(\Phiws\BaseTunnel $tunnel, \Phiws\FrameHeader $header, &$buffer, &$appDataStart) {
    return $this->checkHeader($header, $this->inboundLimit, 'received');
  }

  function sendRawFrames(BT $cx, array &$frames) {
    foreach ($frames as $frame) {
      $res = $this->checkHeader($frame->header(), $this->outboundLimit, 'to be sent');
      if (isset($res)) { return $res; }
    }
  }

  protected function checkHeader(\Phiws\FrameHeader $header, $limit, $action) {
    if ($header->payloadLength > $limit) {
      $msg = "maximum payload length of $limit bytes exceeded ($header->payloadLength $action)";
      $tunnel->disconnectAndThrow(new \Phiws\StatusCodes\MessageTooBig($msg));
      return false;
    }
  }

  /**
   * Extension
   */

  function inHandshake() {
    return false;
  }

  function position() {
    if ($this->outboundLimit > 0) {
      switch ($this->fragmentMode) {
      case 'before':
        return '<';
      case 'after':
        return '>';
      }
    }
  }

  function sendProcessor(array $frames, \Phiws\Pipeline $pipe) {
    $max = $this->outboundLimit;
    $fragmentingFrame = $offset = null;

    return function () use (&$frames, &$fragmentingFrame, $max) {
      while (true) {
        if (!$fragmentingFrame) {
          $frame = array_shift($frames);

          if (!$frame or $frame->payloadLength() <= $max) {
            // No more frames to process or current frame is already small.
            return $frame;
          }

          $pipe->log("fragmenting {$frame->describe()}...");
          $fragmentingFrame = $frame;
          $offset = 0;
        }

        // Processing a big frame into fragments.
        if ($offset < $total) {
          $frag = $fragmentingFrame->makeFragment($offset, $max - 1);
          $pipe->log("...fragment @ $offset: {$frag->describe()}");
          $offset += $frag->payloadLength();
          return $frag;
        } else {
          // Last fragment already processed, continue to the next new frame.
          $pipe->log("fragmented {$fragmentingFrame->describe()}");
          $fragmentingFrame = null;
        }
      }
    };
  }
}
