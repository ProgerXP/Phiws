<?php namespace Phiws\DataSources;

use Phiws\Exceptions\EStream;

class StringDS extends \Phiws\DataSource {
  protected $data;
  protected $size;

  function __construct($data) {
    $this->data = (string) $data;
    $this->size = strlen($data);
  }

  function size() {
    return $this->size;
  }

  function close() {
    $this->data = '';
    $this->size = 0;
  }

  function __toString() {
    return $this->data;
  }

  protected function doCopyTo($handle, $offset, $maxLength) {
    $maxLength < 0 and $maxLength = $this->size();
    $res = EStream::fwrite($handle, substr($this->data, $offset, $maxLength));

    // fwrite() will return less bytes than passed if stream has a zlib.deflate filter
    // but it should never return 0 on success (in contrast, it returns 0 when $handle
    // is read-only).
    if ($res === 0 and $offset < strlen($this->data) and $maxLength > 0) {
      EStream::fail('doCopyTo: fwrite() error');
    }

    return $res;
  }

  protected function doReadChunks($chunkSize, $func) {
    for ($i = 0; $i < $this->size; $i += $chunkSize) {
      $res = call_user_func($func, substr($this->data, $i, $chunkSize));
      if ($res === false) { break; }
    }
  }
}
