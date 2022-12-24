<?php namespace Phiws\DataSources;

use Phiws\Exceptions\EStream;

class Stream extends \Phiws\DataSource {
  protected $autoClose;
  protected $handle;
  protected $size;

  static function fromFile($path) {
    return new static(fopen($path, 'rb'), true);
  }

  static function newTemporary() {
    return new static(\Phiws\Utils::newTempStream(), true);
  }

  // Caller should free $handle upon exception in the constructor, even
  // if $autoClose was given as true.
  // Rewinds $handle to the beginning. Don't use $handle outside of this object
  // even if not $autoClose.
  function __construct($handle, $autoClose) {
    if (!is_resource($handle)) {
      \Phiws\CodeException::fail("DataSources\\Stream: handle is not a resource");
    }

    $this->handle = $handle;
    $this->updateSize();
    $this->autoClose = (bool) $autoClose;
  }

  function __destruct() {
    $this->autoClose and $this->close();
  }

  function autoClose() {
    return $this->autoClose;
  }

  function handle() {
    return $this->handle;
  }

  function updateSize() {
    $stat = EStream::fstat($this->handle);
    $this->size = $stat['size'];
  }

  // Warning; it's unusable on streams with READ filters (like zlib.inflate) since
  // they change data as it passes.
  function size() {
    return $this->size;
  }

  function close() {
    \Phiws\Utils::fcloseAndNull($this->handle);
  }

  function seek($offset = 0, $pos = SEEK_SET) {
    EStream::fseek($this->handle, $offset, $pos);
  }

  // Don't use readChunks() if source stream has a READ filter.
  protected function doReadChunks($chunkSize, $func) {
    $offset = 0;

    do {
      $this->seek($offset);
      // We can't reliably tell when $handle has no more data (the same applies to
      // stream_copy_to_stream()).
      // 1. feof() is unreliable (for TCP connections and php://input - always false).
      // 2. size() can't be used when filters are active. E.g. zlib.deflate
      //    will return different amount of data than available in the stream.
      $chunk = EStream::fread($this->handle, $chunkSize);
      $offset += strlen($chunk);

      if (strlen($chunk) and call_user_func($func, $chunk) === false) {
        $chunk = '';
      }
    } while (strlen($chunk));
  }

  protected function doCopyTo($handle, $offset, $maxLength) {
    // See https://github.com/php/doc-en/issues/2046.
    $this->seek($offset);
    return EStream::stream_copy_to_stream($this->handle, $handle, $maxLength);
  }

  // If $handle has a WRITE filter then number of copied (written) bytes
  // will differ from ftell() of the source stream. copyEx() updates $offset with
  // source handle position after copy so that next call will continue from the same
  // position.
  //
  //   for ($offset = 0; copyEx($dest, $offset, 8192) > 0; );
  //
  // Don't use copyTo/Ex when source has a READ filter since fseek() won't work
  // and ftell/fwrite/stream_copy_to_stream will return number of uncompressed
  // bytes, not bytes that have been read (typially less than uncompressed)>
  function copyEx($handle, &$offset, $maxLength = -1) {
    $written = $this->copyTo($handle, $offset, $maxLength);
    $offset = EStream::ftell($this->handle);
    return $written;
  }
}
