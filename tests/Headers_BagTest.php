<?php namespace Phiws;

use Phiws\Headers\Bag;
use Phiws\Headers\ResponseStatus as Status;

class Headers_BagTest extends \PHPUnit_Framework_TestCase {
  function testInheritance() {
    $bag = new Bag;

    $this->assertTrue( is_subclass_of($bag, 'Countable') );
    $this->assertTrue( is_subclass_of($bag, 'IteratorAggregate') );
  }

  /**
   * @dataProvider giveNormName
   */
  function testNormName($name, $norm) {
    $this->assertSame($norm, (new Bag)->normName($name));
  }

  function giveNormName() {
    return [
      ['a b.c', 'A-B.c'],
      [' Ab_CD', 'Ab-Cd'],
      ['0aB-c', '0ab-C'],
    ];
  }

  function testStatus() {
    $bag = new Bag;
    $this->assertNull($bag->status());

    $status = new Status(500, '');
    $bag->status($status);
    $this->assertSame($status, $bag->status());
    $this->assertSame($status, $bag->status());
  }

  function testGetSetAddJoinClear() {
    $bag = new Bag;
    $this->assertSame(0, count($bag));

    $name = 'Non-No-Rm';
    $value = ' V - a - l ';

    $bag->set('NON_No-rM', $value);
    $this->assertSame(1, count($bag));
    $this->assertSame($value, $bag->get($name));
    $this->assertSame([$value], $bag->getAll($name));

    $name2 = 'Sec-Header';
    $bag->set($name2, 'shv');
    $this->assertSame(2, count($bag));
    $this->assertSame('shv', $bag->get($name2));
    $this->assertSame(['shv'], $bag->getAll($name2));

    $bag->add(' Non-no_rm ', 'v2');
    $this->assertSame(2, count($bag));
    $this->assertSame('v2', $bag->get($name));
    $this->assertSame([$value, 'v2'], $bag->getAll($name));

    $iterated = [];

    foreach ($bag as $header => $values) {
      $iterated[$header] = $values;
    }

    $expected = [
      $name => [$value, 'v2'],
      $name2 => ['shv'],
    ];

    $this->assertSame($expected, $iterated);

    $expected = [
      "$name: $value",
      "$name: v2",
      "$name2: shv",
    ];

    $expected = join("\r\n", $expected)."\r\n";
    $this->assertSame($expected, $bag->join());
    $this->assertSame($expected, (string) $bag);

    $bag->set($name, 'v3');
    $this->assertSame(2, count($bag));
    $this->assertSame('v3', $bag->get($name));
    $this->assertSame(['v3'], $bag->getAll($name));

    $bag->clear();
    $this->assertSame(0, count($bag));
    $this->assertNull($bag->get($name));
    $this->assertSame("\r\n", $bag->join());
  }

  function testGetNone() {
    $bag = new Bag;
    $this->assertNull($bag->get('x'));
    $this->assertNull($bag->get('X'));

    foreach (['', false, null, 0] as $value) {
      $bag->set('x', $value);
      $this->assertNull($bag->get('x'));
      $this->assertSame((string) $value, $bag->get('X'));
    }
  }

  function testRemove() {
    $bag = new Bag;

    $bag->add('h1', 'v1');
    $bag->add('h1', 'v2');
    $bag->add('h2', 'v3');

    $this->assertCount(2, $bag);
    $this->assertSame(['v1', 'v2'], $bag->getAll('H1'));

    $bag->remove('h1');
    $this->assertCount(1, $bag);
    $this->assertSame([], $bag->getAll('H1'));
    $this->assertSame(['v3'], $bag->getAll('H2'));

    $bag->remove('H2');
    $this->assertCount(0, $bag);
    $this->assertSame([], $bag->toArray());
  }

  /**
   * @dataProvider giveAddWrongSymbols
   */
  function testAddWrongSymbols($func, $name, $value) {
    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    $this->expectExceptionMessage('wrong symbols');
    (new Bag)->$func($name, $value);
  }

  function giveAddWrongSymbols() {
    $res = [];

    foreach (['add', 'set'] as $func) {
      $res[] = [$func, "na\nme", "value"];
      $res[] = [$func, "name", "va\rlue"];
      $res[] = [$func, "na\0me", "value"];
      $res[] = [$func, "name", "va\0lue"];
    }

    return $res;
  }

  function testToArray() {
    $bag = new Bag;
    $bag->status(new Status(543, 'foo'));
    $bag->set('Hdr1', 'Val1');
    $bag->add('Hdr2', 'Val2');
    $bag->add('Hdr1', 'Val3');
    $result = $bag->toArray();

    $expected = [
      'Hdr1: Val1',
      'Hdr1: Val3',
      'Hdr2: Val2',
    ];

    $this->assertArraySubset($expected, $result);
  }

  function testOutputStatus() {
    http_response_code(432);
    $this->assertSame(432, http_response_code());

    $bag = new Bag;
    $bag->status(new Status(543, 'foo'));
    $bag->set('Hdr', 'Val');

    try {
      $bag->output();
      $this->fail();
    } catch (\PHPUnit_Framework_Error $e) { }

    $this->assertSame(543, http_response_code());
  }

  function testOutputWithoutStatus() {
    http_response_code(432);
    $this->assertSame(432, http_response_code());

    $bag = new Bag;
    $bag->set('Hdr', 'Val');

    try {
      $bag->output();
      $this->fail();
    } catch (\PHPUnit_Framework_Error $e) { }

    $this->assertSame(432, http_response_code());
  }

  function testJoinWithStatus() {
    $bag = new Bag;
    $status = new Status(543, 'foo');
    $bag->status($status);
    $bag->add('Hdr', 'Val');

    $expected = "HTTP/1.1 543 foo\r\nHdr: Val\r\n";
    $this->assertSame($expected, $bag->join());
  }

  function testParseFromStreamWrongHandle() {
    fclose($h = fopen('php://memory', 'r'));
    $this->expectException('PHPUnit_Framework_Exception');
    (new Bag)->parseFromStream($h);
  }

  function testParseFromStreamLongLine() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, 'Header: '.str_repeat('v', 9000)."\r\nHdr2: Val2\r\n\r\n");
    rewind($h);

    $this->expectException(Exceptions\Stream::class);
    $this->expectExceptionMessage('too long');
    (new Bag)->parseFromStream($h);
  }

  function testParseFromStreamLF() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, "Header: Value\nHdr2: Val2\r\n\r\n");
    rewind($h);

    $this->expectException(Exceptions\Stream::class);
    $this->expectExceptionMessage('line break');
    (new Bag)->parseFromStream($h);
  }

  function testParseFromStreamCR() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, "Header: Value\rHdr2: Val2\r\n\r\n");
    rewind($h);

    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    // fgets() ignores standalone \r and reads up to \r\n, with \r becoming
    // part of 'Header's value.
    $this->expectExceptionMessage('wrong symbols');
    (new Bag)->parseFromStream($h);
  }

  function giveStream200() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, "HTTP/1.1 200 OK\r\n hdr :   Val  \r\n\r\n");
    rewind($h);
    return $h;
  }

  function testParseFromStreamNoColonNoStatusArg() {
    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    $this->expectExceptionMessage('colon');
    (new Bag)->parseFromStream($this->giveStream200());
  }

  function testParseFromStreamNoColonWithStatusArg() {
    $bag = (new Bag)
      ->parseFromStream($this->giveStream200(), 'Phiws\\Headers\\ResponseStatus');

    $this->assertSame('HTTP/1.1 200 OK', $bag->status()->join());
    $this->assertSame(1, count($bag));
    $this->assertSame('Val  ', $bag->get('Hdr'));
  }

  function testParseFromStreamTwiceNoColon() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, "HTTP/1.1 200 OK\r\nHdr Val\r\n\r\n");
    rewind($h);

    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    $this->expectExceptionMessage('colon');

    $bag = (new Bag)->parseFromStream($h, 'Phiws\\Headers\\ResponseStatus');
  }

  /**
   * @dataProvider giveParseFromStreamAbruptEOF
   */
  function testParseFromStreamAbruptEOF($eof, $msg) {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, "Hdr: Val$eof");
    rewind($h);

    $this->expectException(Exceptions\Stream::class);
    $this->expectExceptionMessage($msg);

    $bag = (new Bag)->parseFromStream($h);
  }

  function giveParseFromStreamAbruptEOF() {
    return [
      ["", 'malformed'],
      ["\r", 'malformed'],
      ["\n", 'malformed'],
      // Stream is supposed to end on blank line separating headers from data.
      // ...\r\n <> ...\r\n\r\n
      ["\r\n", 'fgets()'],
    ];
  }

  function testParseFromStreamWithData() {
    $data = "Hdr3: Data\r\nHdr4: Data";

    $h = fopen('php://memory', 'w+b');
    fwrite($h, "Hdr1: Val1\r\nHdr2: Val2\r\n\r\n$data");
    rewind($h);

    $bag = (new Bag)->parseFromStream($h);

    $this->assertFalse(feof($h));
    $this->assertSame(2, count($bag));
    $this->assertSame($data, stream_get_contents($h));
  }

  function testMakeAndAddParametrized() {
    $this->assertSame('id', Bag::makeParameterized('id', []));

    $params = [
      'key spc' => 'v1',
      ' key spc ' => 'v2',
      'flag no value' => true,
      'false' => false,
      'null' => null,
      'empty' => '',
      'plain' => 'value',

      'inspace' => 's p c',
      'lspace' => ' s p c',
      'rspace' => 's p c ',
      'lrspace' => ' s p c ',

      'quote' => 'q"e',
      'bkspc' => 'b\\k',
      'bkquo' => 'a"b\\k\\\\"q',
      'comma' => 'a,c',
      'semic' => 'a;s',
      'equ' => 'a=e',
    ];

    $expected = [
      'key spc=v1',
      'key spc=v2',
      'flag no value',
      'false=',
      'null=',
      'empty=',
      'plain=value',

      'inspace="s p c"',
      'lspace=" s p c"',
      'rspace="s p c "',
      'lrspace=" s p c "',

      'quote="q\\"e"',
      'bkspc=b\\k',
      'bkquo="a\\"b\\\\k\\\\\\\\\\"q"',
      'comma="a,c"',
      'semic="a;s"',
      'equ="a=e"',
    ];

    $expected = 'id; '.join('; ', $expected);
    $this->assertSame($expected, Bag::makeParameterized('id', $params));

    $bag = new Bag;
    $bag->addParametrized('h', 'id', $params);
    $bag->addParametrized('h', 'id', ['k' => 'v"\\']);

    $expected = [
      $expected,
      'id; k="v\\"\\\\"',
    ];

    $this->assertSame($expected, $bag->getAll('H'));
  }

  function testGetTokensEmpty() {
    $bag = new Bag;

    $this->assertSame([], $bag->getTokens('z'));

    $bag->set('a', '');
    $this->assertSame([], $bag->getTokens('a'));

    $bag->set('a', ', ');
    $this->assertSame([], $bag->getTokens('a'));
  }

  function testGetTokens() {
    $bag = new Bag;

    $bag->set('a', 'NonO');
    $this->assertSame(['NonO'], $bag->getTokens('A'));

    $bag->add('h', 'tok1, Zok 2 ,t=Ok3');
    $bag->add('h', '');
    $bag->add('H', ',, some; MORE, ,  , Aok ,, ,  ,,  ');

    $expected = ['tok1', 'Zok 2', 't=Ok3', 'some; MORE', 'Aok'];
    $this->assertSame($expected, $bag->getTokens('H'));

    $expected = array_map('strtolower', $expected);
    $this->assertSame($expected, $bag->getTokens('H', true));
  }

  function testGetParametrizedTokens() {
    $bag = new Bag;

    $bag->add('h', 'deflate; no_takeover; no_takeover=please!');
    $bag->add('H', 'DEFLATE; TOTAL-CONTROL=OVER9000, zeeflate; in="and out"');
    $bag->add('h', 'blip');

    $expected = [
      ['deflate', [['no_takeover', true], ['no_takeover', 'please!']]],
      ['DEFLATE', [['TOTAL-CONTROL', 'OVER9000']]],
      ['zeeflate', [['in', 'and out']]],
      ['blip', []],
    ];

    $this->assertSame($expected, $bag->getParametrizedTokens('H'));

    $expected[1] = ['deflate', [['total-control', 'OVER9000']]];
    $this->assertSame($expected, $bag->getParametrizedTokens('H', true));
  }

  function testGetParametrizedTokensNoHeader() {
    $this->assertSame([], (new Bag)->getParametrizedTokens('Z'));
  }

  /**
   * @dataProvider giveGetParametrizedTokensSets
   */
  function testGetParametrizedTokensSets($header, $expected) {
    $bag = new Bag;
    $bag->set('h', $header);
    $this->assertSame($expected, $bag->getParametrizedTokens('H'));
  }

  function giveGetParametrizedTokensSets() {
    return [
      [' i ; k = ', [
        ['i', [['k', '']]],
      ]],

      ['inline-attachment; filename="me\\\\ga\\"ne.gif"; z', [
        ['inline-attachment', [['filename', 'me\\ga"ne.gif'], ['z', true]]],
      ]],

      ['i; k="q\"u",;k2= v2 , i2', [
        ['i', [['k', 'q"u'], ['k2', 'v2']]],
        ['i2', []],
      ]],

      ['i; k=an"ny\\ going; k=; par3==, i2; i2p=" 2pv "; 3p', [
        ['i', [['k', 'an"ny\\ going'], ['k', ''], ['par3', '=']]],
        ['i2', [['i2p', ' 2pv '], ['3p', true]]],
      ]],
      
      ['i; k="q; v", i2; k="q\\", v"; k="q\\\\", i3', [
        ['i', [['k', 'q; v']]],
        ['i2', [['k', 'q", v'], ['k', 'q\\']]],
        ['i3', []],
      ]],
    ];
  }

  /**
   * @dataProvider giveGetParametrizedTokensMalformed
   */
  function testGetParametrizedTokensMalformed($header) {
    $bag = new Bag;
    $bag->set('H', $header);

    $this->expectException(StatusCodes\MalformedHttpHeader::class);
    $bag->getParametrizedTokens('H');
  }

  function giveGetParametrizedTokensMalformed() {
    return [
      // Missing headers is okay but present header with empty value is not.
      [''],
      ['id; param=v, i=d'],
    ];
  }

  // An edge case, ideally MalformedHttpHeader should be thrown but it requires some
  // work to detect so not strictly handled right now.
  function testGetParametrizedTokensUnclosedQuote() {
    $bag = new Bag;
    $bag->set('H', 'i; un="closed, i2');

    $expected = [
      ['i', [['un', '"closed']]],
      ['i2', []],
    ];

    $this->assertSame($expected, $bag->getParametrizedTokens('H'));
  }
}
