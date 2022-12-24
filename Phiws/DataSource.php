<?php namespace Phiws;

abstract class DataSource {
  abstract function size();

  // Returns number of bytes written.
  function copyTo($handle, $offset = 0, $maxLength = -1) {
    $offset < 0 and $offset = 0;
    return $this->doCopyTo($handle, $offset, $maxLength);
  }

  function copyEx($handle, &$offset, $maxLength = -1) {
    $offset += $written = $this->copyTo($handle, $offset, $maxLength);
    return $written;
  }

  // Frees memory/resources. Subsequent calls to this object will error or return EOF.
  function close() { }

  // $func (str $buffer); return false to stop.
  function readChunks($chunkSize, $func) {
    if ($chunkSize <= 0) {
      CodeException::fail("readChunks($chunkSize): chunk size must be positive");
    }

    return $this->doReadChunks($chunkSize, $func);
  }

  function readAll() {
    $res = '';

    $this->readChunks(65536, function ($chunk) use (&$res) {
      $res .= $chunk;
    });

    return $res;
  }

  function readHead($size) {
    if ($size < 0) {
      CodeException::fail("readHead($size): size must be positive");
    }

    $res = '';

    $size and $this->readChunks($size, function ($chunk) use (&$res) {
      $res = $chunk;
      return false;
    });

    return $res;
  }

  abstract protected function doReadChunks($chunkSize, $func);
  // $maxLength can be < 0 to copy all.
  abstract protected function doCopyTo($handle, $offset, $maxLength);
}
