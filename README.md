# Phiws - Pure PHP WebSocket server/client implementation

* Written from scratch in conformance with RFC 7692 and Errata
* Plugin-based architecture with nearly 50 events for fine execution control
* Standard plugins: reconnecting, limits, cookies, HTTP Auth, ping/pong, etc.
* Support for connecting to server through SOCKS proxy ([Phisocks](https://github.com/ProgerXP/Phisocks))
* Support for user WebSocket Extensions and Protocols
* Built-in support for `permessage-deflate` Extension
* Transparent Server-Sent Events backend with `Last-Event-ID` support
* JavaScript interface blending native WebSocket and EventSource
* Over 5000 lines of unit tests
* No external dependencies
* PHP 5.6 and up

## Basic usage

Enable logging to a file or console during development:

```
Phiws\Logger::defaultMinLevel('info');
Phiws\Loggers\InMemory::$dumpOnShutdown = true;
```

Add some useful plugins:

```
Phiws\BaseTunnel::globalPlugins(new Phiws\Plugins\AutoReconnect);
Phiws\BaseTunnel::globalPlugins(new Phiws\Plugins\UserAgent);
```

Specify how to process incoming data:

```
$dpp = new Phiws\Plugins\DataProcessorPicker;
$dpp->proc(Phiws\DataProcessors\BufferAndTrigger::class)->whenIsText();
Phiws\BaseTunnel::globalPlugins($dpp);
Phiws\BaseTunnel::globalPlugins(new JsonData);

class JsonData extends Phiws\StatefulPlugin {
  function events() {
    return ['bufferedFrameComplete'];
  }

  function bufferedFrameComplete($cx, $applicationData = null, $extensionData = null) {
    $data = json_decode($applicationData->readAll());
    $data->HERO = 'WOrld!';
    $cx->queueJsonData($data);
    $cx->gracefulDisconnectAndWait();
  }
}
```

Start a WebSocket server (accept connections from web browser's [WebSocket](https://developer.mozilla.org/en-US/docs/Web/API/WebSocket), Node.js' [ws](https://www.npmjs.com/package/ws), etc.):

```
$server = new Server(8888);
// Add non-global plugins like this:
$server->plugins()->add(new Phiws\Plugins\GarbageCollector);
// Enable data compression:
$server->extensions()->add(new Phiws\Extensions\PerMessageDeflate);
// Echo messages as soon as they appear:
$server->logger()->echoMode(true);
$server->start();
$server->loop();
```

Connect to a WebSocket server (such as to Node.js' `ws`):

```
$client = new Phiws\Client;
// Server and Client share a common base class with general-purpose methods:
$client->logger()->echoMode(true);
$addr = new Phiws\ServerAddress('127.0.0.1', 8888);
$addr->secure(true);
$client->connect($addr);
$client->loop();
```
