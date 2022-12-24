<?php namespace Phiws\StatusCodes;

// "[...] an endpoint (client) is terminating the connection because it has expected the server to negotiate one or more extension, but the server didn't return them in the response message of the WebSocket handshake."
// "Note that this status code is not used by the server, because it can fail the WebSocket handshake instead."
class ClientExtensionsNotNegotiated extends PreHandshakeCode {
  const CODE = 1010;
  const TEXT = 'Client Extensions Not Negotiated';
}
