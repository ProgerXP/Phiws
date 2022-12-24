<?php namespace Phiws\Plugins;

class GarbageCollector extends \Phiws\Plugin {
  protected $memThreshold;
  protected $memThresholdBytes;

  function events() {
    return ['resetContext', 'serverClientAccepted'];
  }

  function memThreshold($value = null) {
    return \Phiws\Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($value) {
      if (isset($value)) {
        $this->memThresholdBytes = $value;
      } else {
        $ini = strtoupper(ini_get('memory_limit'));
        // No configured limit.
        $ini < 0 and $ini = '1G';
        $ini = ((int) $ini) * 1024 * 1024 * (substr($ini, -1) === 'G' ? 1024 : 1);
        $this->memThresholdBytes = round($ini * .9);
      }

      return $value;
    });
  }

  function memThresholdBytes() {
    return $this->memThresholdBytes;
  }

  function resetContext(\Phiws\BaseObject $cx) {
    $this->memThreshold($this->memThreshold);
  }

  function serverClientAccepted(\Phiws\Server $cx, \Phiws\ServerClient $client) { 
    $mem = memory_get_usage();
    $msg = sprintf('GC: %dM mem (%dM threshold)', $mem / 1024 / 1024, $this->memThresholdBytes / 1024 / 1024);

    if ($mem >= $this->memThresholdBytes) {
      $msg .= '; cleaned up '.gc_collect_cycles().' cycles';
    }

    $cx->log($msg);
  }
}
