<?php namespace Phiws;

use Phiws\Headers\Bag;

class TestServerClient extends ServerClient {
  function __construct(Server $server = null) {
    parent::__construct();
    $this->state = static::CLOSED;
  }
}

class TestExtPlugin extends Plugin {
  function events() {
    return [];
  }
}

class TestCaseSensExtension1 extends Extension {
  const ID = 'tcseA';

  public $isActive = true;

  function isActive() {
    return $this->isActive;
  }
}

class TestCaseSensExtension2 extends TestCaseSensExtension1 {
  const ID = 'tcsea';
}

class ExtensionsTest extends \PHPUnit_Framework_TestCase {
  /**
   * @beforeClass
   */
  static function loadTestExtensionClasses() {
    if (!class_exists(ExtensionTest::class)) {
      require_once __DIR__.'/ExtensionTest.php';
    }
  }

  function testConstants() {
    $this->assertSame('Sec-Websocket-Extensions', Extensions::HEADER);
  }

  function testTunnelAndLog() {
    $exts = new Extensions;
    $this->assertNull($exts->tunnel());
    // No exception on null object access, ignored.
    $exts->log('msg', null, 'warn');

    $exts = new Extensions($client = new Client);
    $this->assertCount(0, $client->logger());
    $this->assertSame($client, $exts->tunnel());

    $exts->tunnel(new Client);
    $this->assertSame($client, $exts->tunnel());

    $exts->log($id = uniqid(), null, 'warn');
    $this->assertCount(1, $client->logger());

    list($message) = $client->logger()->messages();
    $this->assertSame($id, $message->message);
  }

  function testAllAndActive() {
    $exts = new Extensions;
    $exts->add($ext1 = new TestExtension);
    $exts->add($ext2 = new TestExtension2);

    $all = [
      $ext1->id() => $ext1,
      $ext2->id() => $ext2,
    ];

    $this->assertSame($all, $exts->all());
    $this->assertSame([], $exts->active());
    $this->assertSame([], $exts->activeIDs());
  }

  function testAddDuplicateId1() {
    $exts = new Extensions;
    $exts->add(new TestExtension);

    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('duplicate');
    $exts->add(new TestExtensionSameID);
  }

  function testAddDuplicateId2() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('duplicate');
    $exts->add($ext);
  }

  function testAddDuplicateId3() {
    $exts = new Extensions;
    $exts->add(new TestExtension);

    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('duplicate');
    $exts->add(new TestExtension);
  }

  function testAddBlankID() {
    $exts = new Extensions;
    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('blank');
    $exts->add(new TestExtensionBlankID);
  }

  function testAddWrongClass() {
    $exts = new Extensions;
    $pi = new TestExtPlugin;

    $this->expectException('PHPUnit_Framework_Error');
    $exts->add($pi);
  }

  function testGet() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);
    $this->assertSame($ext, $exts->get(TestExtension::ID));
  }

  function testGetUnlisted() {
    $exts = new Extensions;
    $exts->add(new TestExtension);
    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('unknown');
    $exts->get(TestExtension2::ID);
  }

  function testClientBuildHeaders() {
    $exts = new Extensions;
    $ext = new TestExtension;
    $exts->add($ext);
    $exts->add($ext2 = new TestExtension2);

    $ext->suggestParams = [
      ['ablank' => '', 'abool' => true],
      ['atext' => 'T', 'ablank' => null],
    ];

    $expected = [
      't-ext; ablank=; abool',
      't-ext; atext=T; ablank=',
      'te2',
    ];

    $bag = new Bag;
    $exts->clientBuildHeaders(new Client, $bag);
    $this->assertSame($expected, $bag->getAll($exts::HEADER));

    $this->assertSame([$ext, $ext2], array_values($exts->active()));
    $this->assertSame(['t-ext', 'te2'], $exts->activeIDs());
  }

  function testClientBuildHeadersEmptySuggest() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);
    $ext->suggestParams = [];

    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('suggest');
    $exts->clientBuildHeaders(new Client, new Bag);
  }

  function testClientBuildHeadersNonArraySuggestSet() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);
    $ext->suggestParams = ['z'];

    $this->expectException('PHPUnit_Framework_Error');
    $exts->clientBuildHeaders(new Client, new Bag);
  }

  /**
   * @dataProvider giveClientBuildHeadersInactive
   */
  function testClientBuildHeadersInactive($props) {
    $exts = new Extensions;
    $ext = new TestExtension;
    $exts->add($ext);
    $exts->add($ext2 = new TestExtension2);

    foreach ($props as $prop => $value) {
      $ext->$prop = $value;
    }

    $ext->suggestParams = [['ablank' => '']];

    $bag = new Bag;
    $exts->clientBuildHeaders(new Client, $bag);
    $this->assertSame(['te2'], $bag->getAll($exts::HEADER));

    $this->assertSame([$ext2], array_values($exts->active()));
    $this->assertSame(['te2'], $exts->activeIDs());
  }

  function giveClientBuildHeadersInactive() {
    return [
      [['isActive' => false]],
      [['inHandshake' => false]],
      [['isActive' => false, 'inHandshake' => false]],
    ];
  }

  function testClientBuildHeadersCaseSensitive() {
    $exts = new Extensions;
    $exts->add($ext = new TestCaseSensExtension1);
    $this->assertSame('tcseA', $ext->id());

    $bag = new Bag;
    $exts->clientBuildHeaders(new Client, $bag);

    $this->assertSame(['tcseA' => $ext], $exts->all());
    $this->assertSame(['tcsea' => $ext], $exts->active());
    $this->assertSame('tcseA', $bag->get($exts::HEADER));
  }

  function testClientBuildHeadersDuplicateCaseSensitive() {
    $exts = new Extensions;
    $exts->add(new TestCaseSensExtension1);
    $exts->add(new TestCaseSensExtension2);

    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('sensitive');
    $exts->clientBuildHeaders(new Client, new Bag);
  }

  function testClientCheckHeaders() {
    $cx = new Client;
    $exts = new Extensions($cx);
    $exts->add($ext = new TestExtension);

    $exts->clientBuildHeaders($cx, new Bag);
    $this->assertSame(['t-ext'], $exts->activeIDs());

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-Ext; abLank='.($id = uniqid()));

    $exts->clientCheckHeaders($cx, $bag);

    $this->assertSame(['t-ext'], $exts->activeIDs());
    $this->assertSame(['ablank' => $id], $ext->params);
    $this->assertTrue($cx->plugins()->has($ext));
  }

  function testClientCheckHeadersCaseSensitive() {
    $exts = new Extensions;
    $exts->add($ext1 = new TestCaseSensExtension1);   // tcseA
    $exts->add($ext2 = new TestCaseSensExtension2);   // tcsea
    $ext2->isActive = false;

    $exts->clientBuildHeaders(new Client, new Bag);
    $this->assertSame(['tcsea' => $ext1], $exts->active());

    $bag = new Bag;
    $bag->add($exts::HEADER, 'tcseA');
    $exts->clientCheckHeaders(new Client, $bag);

    $this->assertSame(['tcseA' => $ext1, 'tcsea' => $ext2], $exts->all());
    $this->assertSame(['tcsea' => $ext1], $exts->active());
  }

  /**
   * @dataProvider giveClientCheckHeadersContext
   */
  function testClientCheckHeadersContext($orig) {
    $exts = new Extensions($orig);
    $exts->add($ext = new TestExtension);

    $bag = new Bag;
    $exts->clientBuildHeaders($cx = new Client, $bag);
    $exts->clientCheckHeaders($cx, $bag);

    $this->assertSame(['t-ext'], $exts->activeIDs());
    // Registration happens with Extension's own context, not what was given to
    // event handlers.
    $this->assertFalse($cx->plugins()->has($ext));
  }

  function giveClientCheckHeadersContext() {
    return [
      [null], [new Client],
    ];
  }

  function testClientCheckHeadersNotNegotiated() {
    $cx = new Client;
    $exts = new Extensions($cx);
    $exts->add($ext2 = new TestExtension2);
    $exts->add($ext = new TestExtension);

    $exts->clientBuildHeaders($cx, new Bag);
    $this->assertSame(['te2', 't-ext'], $exts->activeIDs());
    $this->assertFalse($ext->notNegotiated);

    $bag = new Bag;
    $bag->add($exts::HEADER, 'te2');

    $exts->clientCheckHeaders($cx, $bag);

    $this->assertSame(['te2'], $exts->activeIDs());
    $this->assertTrue($ext->notNegotiated);

    $this->assertFalse($cx->plugins()->has($ext));
    $this->assertTrue($cx->plugins()->has($ext2));
  }

  function testClientCheckHeadersNotNegotiatedError() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);
    $exts->add($ext2 = new TestExtension2);

    $exts->clientBuildHeaders(new Client, new Bag);
    $this->assertSame(['t-ext', 'te2'], $exts->activeIDs());

    $bag = new Bag;
    $bag->add($exts::HEADER, 'te2');
    $ext->notNegotiated = 'error';

    $this->expectException(StatusCodes\ClientExtensionsNotNegotiated::class);
    $this->expectExceptionMessage($ext->id());
    $exts->clientCheckHeaders(new Client, $bag);
  }

  function testClientCheckHeadersDuplicateExts() {
    $exts = new Extensions;
    $exts->add(new TestExtension);
    $exts->add(new TestExtension2);

    $exts->clientBuildHeaders(new Client, new Bag);
    $this->assertSame(['t-ext', 'te2'], $exts->activeIDs());

    $bag = new Bag;
    $bag->add(Extensions::HEADER, 'te2');
    $bag->add(Extensions::HEADER, 't-ext, te2');

    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    $this->expectExceptionMessage('duplicate');
    $exts->clientCheckHeaders(new Client, $bag);
  }

  function testClientCheckHeadersUnlistedExt() {
    $exts = new Extensions;
    $exts->add(new TestExtension);

    $exts->clientBuildHeaders(new Client, new Bag);
    $this->assertSame(['t-ext'], $exts->activeIDs());

    $bag = new Bag;
    $bag->add($exts::HEADER, 'te2');

    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage('suggested');
    $exts->clientCheckHeaders(new Client, $bag);
  }

  function testClientCheckHeadersOffHandshake() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $exts->clientBuildHeaders(new Client, new Bag);
    $this->assertSame(['t-ext'], $exts->activeIDs());

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext');
    $ext->inHandshake = false;

    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage('suggested');
    $exts->clientCheckHeaders(new Client, $bag);
  }

  function testClientCheckHeadersDuplicateParams() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $exts->clientBuildHeaders(new Client, new Bag);
    $this->assertSame(['t-ext'], $exts->activeIDs());

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext; ablank=; abool; ablank=foo');

    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    $this->expectExceptionMessage('duplicate parameter');
    $exts->clientCheckHeaders(new Client, $bag);
  }

  function testClientCheckHeadersEmpty() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);
    $exts->add(new TestExtension2);

    $exts->clientBuildHeaders(new Client, new Bag);
    $exts->clientCheckHeaders(new Client, new Bag);

    $this->assertTrue($ext->notNegotiated);
    $this->assertSame([], $exts->active());
  }

  function testServerCheckHeaders() {
    $cx = new TestServerClient;
    $exts = new Extensions($cx);
    $exts->add($ext = new TestExtension);
    $exts->add($ext2 = new TestExtension2);

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext; abool; atext='.($id = uniqid()));
    $bag->add($exts::HEADER, 't-ext, t-ext; ablank=123');

    $ext->suitable = ['abool' => true, 'atext' => $id];

    $exts->serverCheckHeaders($cx, $bag);

    $this->assertSame([$ext], array_values($exts->active()));
    $this->assertSame(['t-ext'], $exts->activeIDs());

    $this->assertSame($ext->suitable, $ext->params);
    $this->assertTrue($cx->plugins()->has($ext));
    $this->assertFalse($cx->plugins()->has($ext2));
  }

  function testServerCheckHeadersUnlistedExt() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $bag = new Bag;
    $bag->add($exts::HEADER, 'te2');

    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage('te2');
    $exts->serverCheckHeaders(new TestServerClient, $bag);
  }

  function testServerCheckHeadersOffHandshake() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext; abool');
    $ext->inHandshake = false;

    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage('t-ext');
    $exts->serverCheckHeaders(new TestServerClient, $bag);
  }

  function testServerCheckHeadersDuplicateParams() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext; ablank=123; abool; ablank=, t-ext');

    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    $this->expectExceptionMessage('duplicate parameter');
    $exts->serverCheckHeaders(new TestServerClient, $bag);
  }

  function testServerCheckHeadersNoSuitable() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext; abool');
    $bag->add($exts::HEADER, 't-ext; ablank=123');

    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage('suitable');
    $exts->serverCheckHeaders(new TestServerClient, $bag);
  }

  function testServerCheckHeadersEmptySuitable() {
    $cx = new TestServerClient;
    $exts = new Extensions($cx);
    $exts->add($ext = new TestExtension);
    // $ext->suitable defaults to [].

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext; ablank=123, t-ext');

    $exts->serverCheckHeaders($cx, $bag);

    $this->assertSame([$ext], array_values($exts->active()));
    $this->assertSame([], $ext->params);
    $this->assertTrue($cx->plugins()->has($ext));
  }

  function testServerBuildHeaders() {
    $exts = new Extensions;
    $exts->add($ext2 = new TestExtension2);
    $exts->add($ext = new TestExtension);

    $bag = new Bag;
    // Reverse order than in the object - it should be taken.
    $bag->add($exts::HEADER, 't-ext, te2');

    $exts->serverCheckHeaders(new TestServerClient, $bag);
    $this->assertSame(['t-ext', 'te2'], $exts->activeIDs());
    $this->assertSame([], $ext->params);

    $ext->params = [
      'abool' => true,
      'ablank' => '123',
      'atext' => $id = uniqid(),
    ];

    $expected = [
      "t-ext; abool; atext=$id; atextplus=++",
      'te2',
    ];

    $bag = new Bag;
    $exts->serverBuildHeaders(new TestServerClient, $bag);

    $this->assertSame(['t-ext', 'te2'], $exts->activeIDs());
    $this->assertSame($expected, $bag->getAll($exts::HEADER));
  }

  function testServerBuildHeadersSomeInactive() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);
    $exts->add($ext2 = new TestExtension2);

    $bag = new Bag;
    $bag->add($exts::HEADER, 'te2');

    $exts->serverCheckHeaders(new TestServerClient, $bag);
    $this->assertSame(['te2'], $exts->activeIDs());

    $bag = new Bag;
    $exts->serverBuildHeaders(new TestServerClient, $bag);

    $this->assertSame(['te2'], $exts->activeIDs());
    $this->assertSame(['te2'], $bag->getAll($exts::HEADER));
  }

  function testServerBuildHeadersOffHandshake() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $bag = new Bag;
    $bag->add($exts::HEADER, 't-ext');

    $exts->serverCheckHeaders(new TestServerClient, $bag);

    $ext->inHandshake = false;
    $bag = new Bag;
    $exts->serverBuildHeaders(new TestServerClient, $bag);

    $this->assertSame(['t-ext'], $exts->activeIDs());
    $this->assertSame([], $bag->getAll($exts::HEADER));
  }

  /**
   * @dataProvider giveOffHandshake
   */
  function testOffHandshake($pos, $order, $client) {
    $cx = $client ? new Client : new TestServerClient;
    $exts = new Extensions($cx);
    $exts->add($ext3 = new TestExtension3);
    $exts->add($ext2 = new TestExtension2);
    $exts->add($ext4 = new TestExtension4);
    $exts->add($ext = new TestExtension);

    $ext->inHandshake = false;
    $ext->position = $pos;

    $bag = new Bag;
    $bag->add($exts::HEADER, 'te3, te2');

    if ($client) {
      $exts->clientBuildHeaders($cx, new Bag);
      $this->assertSame(['te3', 'te2', 'te4'], $exts->activeIDs());

      $exts->clientCheckHeaders($cx, $bag);
      $this->assertSame($order, $exts->activeIDs());
    } else {
      $exts->serverCheckHeaders($cx, $bag);
      // Server adds off-handshakes once client headers are received (because it's
      // when it decides on the final extensions).
      $this->assertSame($order, $exts->activeIDs());

      $exts->serverBuildHeaders($cx, new Bag);
    }

    $this->assertTrue($cx->plugins()->has($ext2));
    $this->assertTrue($cx->plugins()->has($ext3));

    if (in_array($ext->id(), $order)) {
      $this->assertTrue($cx->plugins()->has($ext));
    }
  }

  function giveOffHandshake() {
    $tests = [
      ['<', ['t-ext', 'te3', 'te2']],
      ['>', ['te3', 'te2', 't-ext']],
      ['te2', ['te3', 't-ext', 'te2']],
      [null, ['te3', 'te2']],
      [false, ['te3', 'te2']],
      ['Te2', ['te3', 'te2']],
    ];

    $res = [];

    foreach ($tests as $args) {
      foreach ([true, false] as $client) {
        $args[2] = $client;
        $res[] = $args;
      }
    }

    return $res;
  }

  function testOffHandshakeOnClientInactive() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $ext->isActive = false;
    $ext->inHandshake = false;

    $bag = new Bag;
    $exts->clientBuildHeaders(new Client, $bag);
    $exts->clientCheckHeaders(new Client, $bag);
    $this->assertSame([], $exts->activeIDs());
  }

  function testOffHandshakeOnServerInactive() {
    $exts = new Extensions;
    $exts->add($ext = new TestExtension);

    $ext->isActive = false;
    $ext->inHandshake = false;

    $bag = new Bag;
    $exts->serverCheckHeaders(new TestServerClient, $bag);
    $exts->serverBuildHeaders(new TestServerClient, $bag);
    $this->assertSame([], $exts->activeIDs());
  }
}
