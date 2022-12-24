<?php namespace Phiws;

use Phiws\Headers\RequestStatus;
use Phiws\Headers\ResponseStatus;

class Headers_StatusTest extends \PHPUnit_Framework_TestCase {
  function testInheritance() {
    $ref = new \ReflectionClass('Phiws\\Headers\\Status');
    $this->assertTrue($ref->isAbstract());

    $this->assertTrue( is_subclass_of('Phiws\\Headers\\RequestStatus', 'Phiws\\Headers\\Status') );
    $this->assertTrue( is_subclass_of('Phiws\\Headers\\ResponseStatus', 'Phiws\\Headers\\Status') );
  }

  function testConstants() {
    $this->assertSame('Switching Protocols', ResponseStatus::SWITCHING);
  }

  /**
   * @dataProvider giveResponseStatusFrom
   */
  function testResponseStatusFrom($str, $httpVersion, $code, $text) {
    $status = ResponseStatus::from($str);

    $this->assertSame($httpVersion, $status->httpVersion());
    $this->assertSame($code, $status->code());
    $this->assertSame($text, $status->text());
  }

  function giveResponseStatusFrom() {
    return [
      ['HTTP/0.0  000   0 ', 0.0, 0, '0'],
      ['HTTP/1.0 043 dig Dig ', 1.0, 43, 'dig Dig'],
      ['HTTP/1.1 666 Datacenter  Flooded', 1.1, 666, 'Datacenter  Flooded'],
      ['http/0.9 200 _oKay', 0.9, 200, '_oKay'],
      ['http/0.990 200 OK', 0.99, 200, 'OK'],
      ["http/2.0 200 line\rbreak", 2.0, 200, "line\rbreak"],
      ["Http/2 200 OK", 2.0, 200, "OK"],
    ];
  }

  /**
   * @dataProvider giveResponseStatusFromError
   */
  function testResponseStatusFromError($str) {
    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    ResponseStatus::from($str);
  }

  function giveResponseStatusFromError() {
    return [
      ["HTTP/l.0 200 OK"],
      ["HTTP/1.l 200 OK"],
      ["HTTP/.9 200 OK"],
      ["http/1. 200 OK"],
      ["http/01.0 200 OK"],
      ["HTTP/1.0 20 OK"],
      ["HTTP/1.0 200"],
      ["200 OK"],
      ["HTTP/1.1 OK"],
      ["HTTP/1.1"],
      ["200"],
      ["OK"],
      ["1.1 200 OK"],
      ["/1.1 200 OK"],
      ["HTTP/1.0 200.OK"],
      [" HTTP/1.1 200 OK"],
      ["\tHTTP/1.1 200 OK"],
      ["HTTP/1.1\t200 OK"],
      ["HTTP/1.1 200 O\nK"],
    ];
  }

  function testResponseConstructorJoin() {
    $status = new ResponseStatus(' 400', ' Te Xt ', 1);

    $this->assertSame(400, $status->code());
    $this->assertSame('Te Xt', $status->text());
    $this->assertSame(1.0, $status->httpVersion());

    $this->assertSame('HTTP/1.0 400 Te Xt', $status->join());
    $this->assertSame('HTTP/1.0 400 Te Xt', (string) $status);

    $status = new ResponseStatus(0, '   ', 0);
    $this->assertSame('HTTP/0.0 000 ', $status->join());

    $status = new ResponseStatus(1000, '0', 0.99);
    $this->assertSame('HTTP/0.99 1000 0', $status->join());
  }

  /**
   * @dataProvider giveRequestStatusFrom
   */
  function testRequestStatusFrom($str, $httpVersion, $method, $uri) {
    $status = RequestStatus::from($str);

    $this->assertSame($httpVersion, $status->httpVersion());
    $this->assertSame($method, $status->method());
    $this->assertSame($uri, $status->uri());
  }

  function giveRequestStatusFrom() {
    return [
      ["GET /path/to?ar&g=s HTTP/0.9", 0.9, 'GET', '/path/to?ar&g=s'],
      ["Ghott  poo=paa   HttP/9  ", 9.0, 'GHOTT', 'poo=paa'],
      ['DElete / HTTP/1.0', 1.0, 'DELETE', '/'],
    ];
  }

  /**
   * @dataProvider giveRequestStatusFromError
   */
  function testRequestStatusFromError($str) {
    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    RequestStatus::from($str);
  }

  function giveRequestStatusFromError() {
    return [
      [" GET / HTTP/1.0"],
      ["g0t / HTTP/1.0"],
      ["G.T / HTTP/1.0"],
      ["GET  HTTP/1.0"],
      ["GET HTTP/1.0"],
      ["HTTP/1.0"],
      ["GET / HTTP/1."],
      [" GET / HTTP/.9"],
      ["/ HTTP/10"],
      ["GET / "],
      ["/ HTTP/1.0"],
      ["GET /HTTP/1.0"],
      ["GET/ HTTP/1.0"],
    ];
  }

  function testRequestConstructorJoin() {
    $status = new RequestStatus('PatchU', '  /o_O/ ', 1);

    $this->assertSame('PATCHU', $status->method());
    $this->assertSame('/o_O/', $status->uri());
    $this->assertSame(1.0, $status->httpVersion());
    $this->assertSame('1.0', $status->formattedHttpVersion());

    $this->assertSame('PATCHU /o_O/ HTTP/1.0', $status->join());
    $this->assertSame('PATCHU /o_O/ HTTP/1.0', (string) $status);

    $status = new RequestStatus('', '   ', 0);
    $this->assertSame('  HTTP/0.0', $status->join());

    $status = new RequestStatus('G1t', '_', 0.99);
    $this->assertSame('G1T _ HTTP/0.99', $status->join());
  }
}
