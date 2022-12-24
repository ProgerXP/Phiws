<?php namespace Phiws;

class ServerAddressTest extends \PHPUnit_Framework_TestCase {
  function testConstructor() {
    $host = uniqid();
    $port = mt_rand(1, 1000);

    $addr = new ServerAddress($host, $port);

    $this->assertSame($host, $addr->host());
    $this->assertSame($port, $addr->port());

    $this->assertSame($host, $addr->host());
    $this->assertSame($port, $addr->port());
  }

  function testHost() {
    $addr = new ServerAddress('x', 1);
    $addr->host('y');
    $this->assertSame('y', $addr->host());
  }

  function testPort() {
    $addr = new ServerAddress('x', 1);
    $addr->port(2);
    $this->assertSame(2, $addr->port());
  }

  /**
   * @dataProvider giveInvalidHost
   */
  function testInvalidHost($value) {
    $addr = new ServerAddress('x', 1);

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('host:');

    $addr->host($value);
  }

  /**
   * @dataProvider giveInvalidHost
   */
  function testInvalidHostConstructor($value) {
    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('host:');
    new ServerAddress($value, 1);
  }

  function giveInvalidHost() {
    return [[''], [null]];
  }

  /**
   * @dataProvider giveInvalidPort
   */
  function testInvalidPort($value) {
    $addr = new ServerAddress('x', 1);

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('port:');

    $addr->port($value);
  }

  /**
   * @dataProvider giveInvalidPort
   */
  function testInvalidPortConstructor($value) {
    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('port:');
    new ServerAddress('x', $value);
  }

  function giveInvalidPort() {
    return [[0], [-1]];
  }

  function testSecure() {
    $addr = new ServerAddress('x', 1);
    $this->assertFalse($addr->secure());
    $this->assertSame('ws', $addr->scheme());

    $addr->secure(true);
    $this->assertTrue($addr->secure());
    $this->assertSame('wss', $addr->scheme());
  }

  /**
   * @dataProvider giveSecurePort
   */
  function testSecurePort($secure, $port, $portStr, $uri) {
    $addr = new ServerAddress('x', $port);
    $addr->secure($secure);
    $this->assertEquals($portStr, $addr->portWithColon());
    $this->assertEquals($uri, $addr->uri());
  }

  function giveSecurePort() {
    return [
      [false,  80, '',      'ws://x/'],
      [false, 443, ':443',  'ws://x:443/'],
      [false, 777, ':777',  'ws://x:777/'],
      [true,   80, ':80',   'wss://x:80/'],
      [true,  443, '',      'wss://x/'],
      [true,  777, ':777',  'wss://x:777/'],
    ];
  }

  function testPath() {
    $addr = new ServerAddress('x', 1);
    $this->assertSame('', $addr->path());

    $path = uniqid();
    $addr->path($path);
    $this->assertSame($path, $addr->path());

    $addr->path('p');
    $this->assertSame('p', $addr->path());

    $addr->path("/$path");
    $this->assertSame($path, $addr->path());

    $addr->path("///$path");
    $this->assertSame($path, $addr->path());

    $addr->path("///");
    $this->assertSame('', $addr->path());
  }

  function testQuery() {
    $addr = new ServerAddress('x', 1);
    $this->assertSame('', $addr->query());

    $query = uniqid();
    $addr->query($query);
    $this->assertSame($query, $addr->query());

    $addr->query('q');
    $this->assertSame('q', $addr->query());

    $addr->query("?$query");
    $this->assertSame($query, $addr->query());

    $addr->query("???$query");
    $this->assertSame($query, $addr->query());

    $addr->query("???");
    $this->assertSame('', $addr->query());
  }

  /**
   * @dataProvider giveResourceName
   */
  function testResourceName($path, $query, $resName) {
    $addr = new ServerAddress('x', 1);
    $addr->path($path);
    $addr->query($query);
    $this->assertSame($resName, $addr->resourceName());
  }
    
  function giveResourceName() {
    return [
      ['',  '',  '/'],
      ['p', '',  '/p'],
      ['',  'q', '/?q'],
      ['p', 'q', '/p?q'],
    ];
  }

  function testURI() {
    $addr = (new ServerAddress('plum.mulp', 4444))
      ->secure(true)
      ->path('api/ws')
      ->query('key=bazooka');

    $this->assertSame('wss://plum.mulp:4444/api/ws?key=bazooka', $addr->uri());
  }
}
