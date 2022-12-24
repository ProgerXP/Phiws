<?php namespace Phiws;

class ProtoBlankID extends Protocol {
}

class ProtoTestID extends Protocol {
  const ID = 'testprotoid';

  protected $id = 'testprotoid';

  public $someData;

  function id() {
    return $this->id;
  }

  function setID($id) {
    $this->id = $id;
  }
}

class ProtoTestSameID extends Protocol {
  // Same ID as of ProtoTestID.
  const ID = 'testprotoid';
}

class ProtocolsTest extends \PHPUnit_Framework_TestCase {
  function testInheritance() {
    $ref = new \ReflectionClass('Phiws\\Protocol');
    $this->assertTrue($ref->isAbstract());

    $protocols = new Protocols;

    $this->assertTrue($protocols instanceof PluginInterface);
    $this->assertTrue($protocols instanceof \Countable);
    $this->assertTrue($protocols instanceof \IteratorAggregate);
  }

  function testAddBlankID() {
    $protocols = new Protocols;
    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('blank');
    $protocols->add(new ProtoBlankID);
  }

  function testAddDuplicateID() {
    $protocols = new Protocols;
    $protocols->add(new ProtoTestID);

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('duplicate');
    $protocols->add(new ProtoTestID);
  }

  function testAddDiffClassDuplicateID() {
    $protocols = new Protocols;
    $protocols->add(new ProtoTestID);

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('duplicate');
    $protocols->add(new ProtoTestSameID);
  }

  function testGet() {
    $protocols = new Protocols;
    $protocols->add($proto = new ProtoTestID);

    $this->assertSame($proto::ID, $proto->id());
    $this->assertSame($proto, $protocols->get($proto->id()));

    $this->assertNull($protocols->get('foo'));
  }

  function testActive() {
    $protocols = new Protocols;
    $protocols->add($proto = new ProtoTestID);
    $protocols->active($proto);
    $this->assertSame($proto, $protocols->active());

    $protocols->active(null);
    $this->assertNull($protocols->active());
  }

  function testActiveUnlisted() {
    $protocols = new Protocols;

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('unlisted');
    $protocols->active(new ProtoTestID);
  }

  function testList() {
    $proto1 = new ProtoTestID;
    $proto1->setID($id = uniqid());
    $this->assertSame($id, $proto1->id());

    $proto2 = new ProtoTestID;
    $proto2->setID(uniqid());

    $protocols = new Protocols;
    $protocols->add($proto1);
    $protocols->add($proto2);

    $this->assertSame(2, count($protocols));
    $this->assertSame(2, $protocols->count());
    $this->assertSame([$proto1->id(), $proto2->id()], $protocols->ids());

    $this->assertSame($proto1, $protocols->get($proto1->id()));
    $this->assertSame($proto2, $protocols->get($proto2->id()));

    foreach ($protocols as $item) { 
      $this->assertTrue(in_array($item, [$proto1, $proto2], true));
    }

    $protocols->clear();
    $this->assertSame(0, count($protocols));

    foreach ($protocols as $item) { 
      $this->fail();
    }
  }

  function testClone() {
    $proto1 = new ProtoTestID;
    $proto1->setID($id1 = uniqid());

    $proto2 = new ProtoTestID;

    $protocols = new Protocols;
    $protocols->add($proto1);
    $protocols->add($proto2);

    $this->assertNull($protocols->get($id1)->someData);
    $proto1->someData = 'x';
    $this->assertSame('x', $protocols->get($id1)->someData);

    $protocols2 = clone $protocols;

    $this->assertNotSame($protocols, $protocols2);
    $this->assertSame(2, count($protocols2));
    $this->assertNotSame($proto1, $protocols2->get($id1));
    $this->assertNotSame($proto2, $protocols2->get($proto2->id()));

    $this->assertSame('x', $protocols2->get($id1)->someData);
    $protocols->get($id1)->someData = 'y';
    $this->assertSame('y', $protocols->get($id1)->someData);
    $this->assertSame('x', $protocols2->get($id1)->someData);
  }
}
