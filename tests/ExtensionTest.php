<?php namespace Phiws;

class TestExtension extends Extension {
  const ID = 't-ext';

  public $params;

  public $isGlobalHook = false;
  public $suggestParams;
  public $suitable = [];
  public $isActive = true;
  // true, false, 'error'.
  public $notNegotiated = false;
  public $inHandshake = true;
  public $position = '<';

  function isGlobalHook() {
    return $this->isGlobalHook;
  }

  function defaults() {
    return [
      'abool' => true,
      'ablank' => '',
      'atext' => 'bbcb0a40',
    ];
  }

  protected function parse_abool($value) {
    if (!is_booL($value)) {
      StatusCodes\NegotiationError::fail("abool: normalization");
    }
    return $value;
  }

  protected function parse_ablank($value) {
    return $value;
  }

  protected function parse_atext($value) {
    return trim($value);
  }

  function suggestParams() {
    return isset($this->suggestParams) ? $this->suggestParams : [$this->defaults()];
  }

  function isSuitable(array $params) {
    return $this->suitable === $params;
  }

  protected function build_abool($value) {
    return $value;
  }

  protected function build_ablank($value) {
    // Null param should not be added to buildParams() result.
    return null;
  }

  protected function build_atext($value) {
    return [
      'atext' => $value,
      'atextplus' => '++',
    ];
  }

  function isActive() {
    return $this->isActive;
  }

  function notNegotiated() {
    if ($this->notNegotiated === 'error') {
      throw new \Exception(__FUNCTION__);
    }

    $this->notNegotiated = true;
  }

  function inHandshake() {
    return $this->inHandshake;
  }

  function position() {
    return $this->position;
  }

  function testThrowIfIncomplete(array $frames) {
    return $this->throwIfIncomplete($frames);
  }
}

class TestExtensionSameID extends TestExtension {
}

class TestExtensionBlankID extends TestExtension {
  const ID = '';
}

class TestExtension2 extends Extension {
  const ID = 'te2';
}

class TestExtension3 extends Extension {
  const ID = 'te3';

  protected function build_param($value) {
    return ['p' => $value, 'p2' => 'v2'];
  }
}

class TestExtension4 extends Extension {
  const ID = 'te4';
}

class ExtensionTest extends \PHPUnit_Framework_TestCase {
  function testInheritance() {
    $this->assertTrue((new TestExtension) instanceof PluginInterface);
  }

  function testParamAndReset() {
    $ext = new TestExtension;

    $this->assertSame($ext->defaults(), $ext->params);
    $this->assertTrue($ext->param('abool'));
    $this->assertNull($ext->param($id = 'unk'.uniqid()));

    $ext->params[$id] = 'val';
    $this->assertSame('val', $ext->param($id));

    $ext->reset();
    $this->assertSame($ext->defaults(), $ext->params);
  }

  function testParseParams() {
    $params = [
      'ablank' => ' blv ',
      'atext' => ' '.($text = uniqid()).' ',
      'abool' => false,
    ];

    $expected = [
      'ablank' => ' blv ',
      'atext' => $text,
      'abool' => false,
    ];

    $ext = new TestExtension;
    $ext->parseParams($params);
    $this->assertSame($expected, $ext->params);

    // Old parameterts must be cleared.
    $ext->parseParams($params = ['ablank' => '1']);
    $this->assertSame($params, $ext->params);
  }

  function testParseParamsEmpty() {
    $ext = new TestExtension;
    $ext->parseParams([]);
    $this->assertSame([], $ext->params);
  }

  function testParseParamsUnknown() {
    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage('unknown');
    (new TestExtension)->parseParams(['abool' => false, 'unk' => '']);
  }

  function testParseParamsInvalidValue() {
    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage("abool: normalization");
    (new TestExtension)->parseParams(['ablank' => '', 'abool' => 'geeks']);
  }

  function testChooseFrom() {
    $ext = new TestExtension;
    $ext->suitable = ['atext' => 'only business', 'ablank' => ''];

    $this->assertSame($ext->suitable, $ext->chooseFrom([$ext->suitable]));

    $choices = [
      ['atext' => 'only business'],
      ['ablank' => ''],
      $ext->suitable,
    ];

    $this->assertSame($ext->suitable, $ext->chooseFrom($choices));
  }

  function testChooseFromNoSuitable() {
    $ext = new TestExtension;
    $ext->suitable = ['abool' => true];

    $this->expectException(StatusCodes\NegotiationError::class);
    $this->expectExceptionMessage('suitable');

    $ext->chooseFrom([['abool' => false]]);
  }

  function testBuildParams() {
    $ext = new TestExtension;

    $ext->params = [
      'abool' => false,
      'ablank' => '123',
      'atext' => $id = uniqid(),
    ];

    // abool returns true or false 
    // ablank returns null (never in buildParams())
    // atext returns its value and atextplus => '++'

    $expected = [
      'abool' => false,
      'atext' => $id,
      'atextplus' => '++',
    ];

    $this->assertSame($expected, $ext->buildParams());
  }

  function testThrowIfIncompleteOnComplete() {
    (new TestExtension)->testThrowIfIncomplete([]);

    $frames = [new Frames\TextData('test')];
    (new TestExtension)->testThrowIfIncomplete($frames);
  }

  /**
   * @dataProvider giveThrowIfIncompleteOnIncomplete
   */
  function testThrowIfIncompleteOnIncomplete($offset) {
    $incomp = Frames\BinaryData::from(new FrameHeader, $offset);
    $frames = [new Frames\TextData('test'), $incomp];

    $this->expectException(Exceptions\NotEnoughInput::class);
    $this->expectExceptionMessage('complete');
    (new TestExtension)->testThrowIfIncomplete($frames);
  }

  function giveThrowIfIncompleteOnIncomplete() {
    return [
      [Frame::FIRST_PART], [Frame::MORE_PARTS], [Frame::LAST_PART], 
    ];
  }

  function testDefaultResults() {
    $ext = new TestExtension2;

    $this->assertFalse($ext->isGlobalHook());
    $this->assertSame([], $ext->events());
    $this->assertSame('te2', $ext->id());
    $this->assertSame([], $ext->defaults());
    $this->assertSame([[]], $ext->suggestParams());
    $this->assertTrue($ext->isSuitable(['zoo' => 'boo']));
    $this->assertSame([], $ext->buildParams());
    $this->assertTrue($ext->isActive());
    $this->assertTrue($ext->inHandshake());
    $this->assertSame('<', $ext->position());

    $ext = new TestExtension3;
    $expected = ['p' => null, 'p2' => 'v2'];
    $this->assertSame($expected, $ext->buildParams());
    $this->assertSame([$expected], $ext->suggestParams());
  }

  function testTestStubs() {
    $ext = new TestExtension;

    foreach (['isGlobalHook', 'isActive', 'inHandshake'] as $prop) {
      foreach ([true, false] as $value) {
        $ext->$prop = $value;
        $this->assertSame($value, $ext->$prop());
      }
    }

    $ext->suggestParams = [[$id = uniqid()]];
    $this->assertSame([[$id]], $ext->suggestParams());

    $ext->notNegotiated = false;
    $ext->notNegotiated();
    $this->assertTrue($ext->notNegotiated);

    $ext->position = 'z';
    $this->assertSame('z', $ext->position());
  }
}
