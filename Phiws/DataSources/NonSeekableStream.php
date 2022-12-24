<?php namespace Phiws\DataSources;

use Phiws\Exceptions\EStream;

// Warning: typically NonSeekableStream is used for streams with filters (e.g. zlib.*).
// On such streams size() is unreliable (it can return negative if the 
// stream is compressing data, for example - since less bytes were written then fed).
class NonSeekableStream extends Stream {
  function seek($offset = 0, $pos = SEEK_SET) {
    $cur = EStream::ftell($this->handle);

    switch ($pos) {
    case SEEK_SET:    break;
    case SEEK_CUR:    $offset += $cur; break;
    case SEEK_END:    $offset += $this->size; break;
    default:          $offset = 'error!'; 
    }
    
    if ($cur !== +$offset) {
      // Example: incoming frame compressed with permessage-deflate. zlib.* filters
      // don't support rewinding (return empty string after that data portion has been
      // read).
      EStream::fail("rewind: stream is non-seekable");
    }
  }
}
