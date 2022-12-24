<?php namespace Phiws\Loggers;

use Phiws\Exceptions\EStream;

class File extends \Phiws\Logger {
  protected $file;
  protected $handle;
  protected $buffer = [];
  protected $bufferSize = 0;
  // 0 (no limit) or integer. 1 MiB by default.
  protected $fileLimit = 1048576;   

  function __construct($file) {
    $this->file = $file;
  }

  function __destruct() {
    \Phiws\Utils::fcloseAndNull($this->handle);
  }

  /** 
   * Accessors
   */

  function file() {
    return $this->file;
  }

  function handle() {
    return $this->handle;
  }

  // $value = 0 (write to file immediately), integer.
  function bufferSize($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function buffer() {
    return $this->buffer;
  }

  // $value = 0 (no limit), integer.
  function fileLimit($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  /**
   * Logging
   */

  protected function doLog(\Phiws\LogEntry $entry) {
    $this->buffer[] = $entry;

    if (count($this->buffer) + 1 > $this->bufferSize) {
      $this->write($this->buffer);
      $this->buffer = [];
    }
  }

  protected function write(array $entries) {
    $handle = $this->handle ?: $this->handle = EStream::fopen($this->file, 'ab');
    $buffer = '';

    foreach ($entries as $entry) {
      $buffer .= $this->format($entry)."\n";

      if (strlen($buffer) >= 4096) {
        EStream::fwrite($handle, $buffer);
        $buffer = '';
      }
    }

    EStream::fwrite($handle, $buffer);

    if ($limit = $this->fileLimit) {
      $stat = EStream::fstat($handle);
      if ($stat['size'] > $limit) {
        EStream::ftruncate($handle);
      }
    }
  }
}
