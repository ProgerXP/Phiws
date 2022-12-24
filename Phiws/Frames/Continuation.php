<?php namespace Phiws\Frames;

// RFC Errata 4672:
// "These frames MUST be always preceeded by either Text or Binary frame with FIN bit clear (See Section 5.2). The "Payload data" contains next fragment (See section 5.4) of the message whose transmission were opened by the latest Text or Binary frame and MUST be interpreted in the same way as the initial fragment of the message."
class Continuation extends \Phiws\DataFrame {
  const OPCODE = 0x00;
}
