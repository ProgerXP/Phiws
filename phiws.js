;(function () {
  var consts = {
    CONNECTING:             0,
    OPEN:                   1,
    // In SSE 2 === CLOSED.
    //
    // https://html.spec.whatwg.org/multipage/comms.html#the-websocket-interface:dom-websocket-close-2
    // "The connection is going through the closing handshake, or the close() method has been invoked."
    CLOSING:                2,
    CLOSED:                 3,

    NORMAL_CLOSURE:         1000,
    GOING_AWAY:             1001,
    PROTOCOL_ERROR:         1002,
    UNSUPPORTED_DATA:       1003,
    ABNORMAL_CLOSURE:       1006,
    INVALID_PAYLOAD:        1007,
    POLICY_VIOLATION:       1008,
    MESSAGE_TOO_BIG:        1009,
    INTERNAL_ERROR:         1011,
    SERVICE_RESTART:        1012,
    TRY_AGAIN_LATER:        1013,
    EXTENSION_CODE_MIN:     3000,
    EXTENSION_CODE_MAX:     3999,
    PRIVATE_CODE_MIN:       4000,
    PRIVATE_CODE_MAX:       4999,

    // https://html.spec.whatwg.org/multipage/comms.html#server-sent-events-intro
    DEFAULT_EVENT:          'message',
  }

  // Each connection backend is defined by an options object with keys:
  // - url - of form [ws[s]:][//]path. If protocol is missing or is not ws/wss -
  //   Server-Sent Events (SSE) mode is used. Examples:
  //     //sse.php   https://site.org/still/sse   ws://ww2.example.com/websock.
  // - withCredentials - SSE; bool; CORS control: https://html.spec.whatwg.org/multipage/infrastructure.html#attr-crossorigin-use-credentials
  // - protocols - WS; string or array of string.
  //
  // Constructor arguments:
  // - url - connection backend(s), can be a string (URLs), an array of strings
  //   (URLs) or an array of objects (backend options).
  // - opt - default parameters for all backends, as an options object.
  //
  // Final backend options are calculated as follows:
  //
  //   Phiws defaults < opt (default parameters) < url (backend-specific parameters)
  //
  // Backends are tried in sequence until a successful handshake has been made.
  //
  // Connection attempt for a backend will immediately fail if:
  // - (WS) url contains '#'
  // - (WS) protocols have duplicate names or invalid symbols
  //
  // Phiws interface is 99% compatible with both EventSource and WebSocket so
  // if you don't need extra features just use it in the standard way:
  //
  //   var pw = new Phiws('ws://app.site/websock')
  //   pw.onwsmessage = function (e) {
  //     alert('Received a WebSocket frame')
  //   }
  //
  //   var pw = new Phiws('//app.site/sse.php')
  //   pw.addEventListener('message', function (e) {
  //     alert('Received an SSE message of event ' + e.type +
  //           ' saying: ' + e.data)
  //   })
  //
  // An example with multiple backends:
  //
  //   var pw = new Phiws([
  //     'ws://app.site/ideal.backend?user=123',
  //     {
  //       url: '//fallback-sse.php?user=123',
  //       withCredentials: true,
  //       connectTimeout: 60000,
  //     }
  //   ], {
  //     log: true,
  //     autoConnect: false,
  //   });
  //
  //   pw.pwOn('message', function (e) {
  //     alert('Received somethin\': ' + e.data)
  //   });
  //
  //   // Also supporting old-style handlers:
  //   pw.onopen = function () {
  //     alert('Now connected to ' + this.pwOption('url') +
  //           ' using ' + (this.pwWebSocket ? 'WebSocket' : 'SSE'))
  //   };
  //
  //   pw.pwConnect();
  //
  // If you don't like prefixes then call pwEasyAliases() (but this might clash
  // with fields added to WebSocket/EventSource in the future):
  //
  //   new Phiws...
  //     .pwEasyAliases()
  //     .on('message', ...)
  //     .connect()
  var root = window.Phiws = function (url, opt) {
    var self = {
      /**
       * Protected Properties
       */

      // - PW - options that should be given as default options to the constructor;
      //   they will have no effect if given as per-backend options.
      // - BE - options that can be set per-backend and will still be effective.
      _pwDefaults: {
        // BE.
        url: null,
        // BE (SSE).
        withCredentials: false,
        // BE (WS).
        protocols: '',

        // BE.
        log: false,
        // PW. If set, calls pwEasyAliases() in the constructor.
        easyAliases: false,

        // BE (WS). Longer messages will be returned as Blob or ArrayBuffer (depending on
        // binaryType which is 'blob' by default).
        longestString: 1024 * 1024,

        // BE (WS). If not empty, reconnecting to a WS backend will add this parameter
        // to the URL. For example, if last received message had ID 123 then URL will
        // look like ws://foo/bar.php?existing=parameter&...&last-event-id=123.
        lastEventIdParameter: 'last-event-id',

        // BE (WS). If set, regular newMessage/message[:EVENT] will be fired for messages that
        // has empty data. Most cases are only applicable to WS (like with only
        // comments or 'retry') but some are also for SSE (like with empty 'data' field).
        ignoreEmptyMessages: true,

        // BE (SSE). Special event name that, once received, indicates that next
        // onsseerror means the server wants the client to really stop, not reconnect
        // (even though this is what the browser will indicate; see the comment).
        stopSseEvent: 'phiws-stop',

        // BE (SSE). Due to API limitation, it's not possible to listen to "all"
        // event types - messages without event field or with empty/default ('message')
        // value will fire onmessage while others will fire specific addEventListener
        // handlers. Types without registered type won't fire anything.
        // If this is set, server is expected to prefix data of every message with
        // that message's type, then a line break. Example: 'my-type\n{"my":"data"}...'.
        // If this is enabled, newMessage will fire for all incoming types. If disabled,
        // it will fire only for registered types (via addEventListener, onmessage or
        // pwOn). WS doesn't have this problem so it's always "enabled".
        // This will cause problems if server doesn't support it!
        // Value true will check for basic type sanity to prevent them, value 'force'
        // will accept any type, false disables prefixing.
        ssePrefixWithEventType: false,

        // PW. If set, connect() is called by the constructor.
        autoConnect: true,
        // BE. Delay (ms) between trying next backend after a failure.
        connectDelay: 0,
        // BE. Time (ms) given after creating WebSocket/EventSource for it to fire
        // a connection or error event.
        connectTimeout: 3000,

        // BE (WS; SSE reconnection is controlled by the browser). If false -
        // reconnecting is disabled (even if WS server has indicated it's not a failure).
        // If number - is the delay in ms. If an array - random delay: [min, max].
        // WS docs specify reconnects to be in faily large random 5-30 sec range.
        // http://www.ietf.org/mail-archive/web/hybi/current/threads.html#09670
        reconnectDelay: [1000, 5000],
        // BE (WS).
        reconnectCodes: [1012],
        // BE (WS). Effective after successful initial connect.
        tryNextBackendCodes: [1013],
      },

      _pwBackends: null,
      // After connection, has 'index' key with backend index.
      _pwOptions: null,
      _pwState: 3,
      _pwWS: null,
      _pwSSE: null,
      _pwConnectors: [],
      _pwConnTimer: null,
      _pwConnLock: 0,
      _pwInfoFrame: null,
      _pwLastEventID: null,
      _pwSseListeners: {},

      // - normalizedBackends (backends) - arg is _pwBackends (array of opt)
      // - closed - can be preceded by 'close' or 'error'
      // - connecting - only fired on the initial connect (not reconnect)
      //
      // - wsConnecting (connector, WebSocket)
      // - sseConnecting (connector, EventSource)
      // - backendsFailure
      // - connectTimeout (connector)
      // - connectError (connector, e)
      // - connected (connector) - this and all preceding connection events are not
      //   fired in case of SSE reconnect (handled by the browser)
      //
      // - (ws|sse)(Open|Message|Error) (e) - WS fires wserror, then wsclose on unclean close
      // - wsClose (e) - WS; e fields:
      //   - wasClean - bool
      //   - code - integer or 0 if unavailable
      //     Certain failures are deliberately concealed from the JS code with uniform
      //     code 1006. This includes situations when handshake has failed due to
      //     non-negotiated extension or protocol.
      //     https://html.spec.whatwg.org/multipage/comms.html#feedback-from-the-protocol:concept-websocket-close-code-2:
      //     "User agents must not convey any failure information to scripts in a way that would allow a script to distinguish the following situations [...]"
      //   - reason - string or '' if unavailable
      // - open (e, phiws) - fired on reconnects too
      // - error (e, WS/ES) [2]
      // - reconnect (e, WS/ES) [3] - before reconnecting; either 1, 2 or 3 are called
      //
      // - newMessage (e, phiws) - for all event types, before message[:EVENT]; if
      //   stopPropagation() is called then message[:EVENT] handlers are not called
      //   (but not if a newMessage handler just returns false); see ssePrefixWithEventType
      //
      // - message[:EVENT] (e, phiws) - after receiving an event of specific type (EVENT);
      //   if EVENT is empty or the default 'message' then just 'message' is fired; e fields:
      //   - type - string (EVENT), never empty (default is 'message')
      //   - data - string (SSE/WS) or Blob/ArrayBuffer (WS, messages longer than longestString)
      //   - origin - URL of the server-side endpoint (after all redirects)
      //   - lastEventId - SSE; scalar
      //   - stopPropagation() - can be used to skip other handlers (same as returning false)
      //   These limitations exist:
      //   - ES.onmessage is only fired for default event types (i.e. messages without
      //     event field, with empty event value or value 'message')
      //   - ES.addEventListener('message', ...) is identical to ES.onmessage
      //   - there is no way to listen to all events; you have to add listeners
      //     one by one for specific event types with addEventListener or use a
      //     server-side workaround, see ssePrefixWithEventType
      //   - (at least Firefox) events with missing data field do not fire
      //     addEventListener/onmessage at all; if such events have 'data' field even
      //     if it's empty like 'data' or 'data:' then the listener is fired
      //     (seems like a bug to me because this behaviour is not in the spec);
      //     Phiws/PHP mitigates this, other servers might not; if not then it behaves
      //     as if ignoreEmptyMessages always true
      _pwEvents: {},

      /**
       * EventSource & WebSocket Fields
       */

      // Standard event handlers, fired before those set with addEventListener/pwOn.
      onopen: null,
      // Called for messages of missing/empty/default 'message' type.
      onmessage: null,
      onerror: null,
      onclose: null,

      get url() {
        return (self._pwWS || self._pwSSE || {}).url
      },

      // SSE.
      get withCredentials() {
        return (self._pwSSE || {}).withCredentials || false
      },

      get readyState() {
        return self._pwState
      },

      // WS.
      //
      // https://html.spec.whatwg.org/multipage/comms.html#dom-websocket-bufferedamount
      // "the number of bytes of application data [...] that have been queued using send() but that [...] had not yet been transmitted to the network. [...] This does not include framing overhead incurred by the protocol, or buffering done by the operating system or network hardware."
      get bufferedAmount() {
        return (self._pwWS || {}).bufferedAmount || 0
      },

      // WS.
      get extensions() {
        return (self._pwWS || {}).extensions || ''
      },

      // WS.
      get protocol() {
        return (self._pwWS || {}).protocol || ''
      },

      // WS.
      get binaryType() {
        return (self._pwWS || {}).binaryType
      },

      // WS. Initally 'blob'; can be set to 'arraybuffer' so that e.data for binary
      // frames will be ArrayBuffer instead of default Blob.
      //
      // https://html.spec.whatwg.org/multipage/comms.html#dom-binarytype-blob
      // "if the attribute is set to "blob", it is safe to spool it to disk, and if it is set to "arraybuffer", it is likely more efficient to keep the data in memory."
      set binaryType(type) {
        return self._pwWS && (self._pwWS.binaryType = type)
      },

      // WS. Allowed data types: string, Blob, ArrayBuffer, ArrayBufferView.
      // All but string data send a Binary frame (0x02), string sends a Text frame (0x01).
      // Abnormally close this WebSocket connection if data to be sent overflows
      // current send buffer (see bufferedAmount); this happens if network can't
      // accept the rate at which data is being produced.
      //
      // There are no ping/pong methods and events because the spec doesn't expose this API.
      send: function (data) {
        return self._pwWS && self._pwWS.send(data)
      },

      // code and reason - WS-only. Errors if code is not 1000 and not within
      // 3000-4999, or if reason is longer than 123 symbols. It seems that after
      // triggering close incoming frames will be ignored by WS.
      //
      // https://html.spec.whatwg.org/multipage/comms.html#the-websocket-interface:dom-websocket-close-3
      // "The close() method does not discard previously sent messages before starting the WebSocket closing handshake — even if, in practice, the user agent is still busy sending those messages, the handshake will only start after the messages are sent."
      close: function (code, reason) {
        self._pwConnLock++
        var oldState = self._pwState

        try {
          self._pwWS && self._pwWS.close(code, reason)
          self._pwSSE && self._pwSSE.close()
        } catch (e) {
          self.pwLog(['close($, $): silenced exception', code, reason], 'warn', e)
        }

        // Unlike SSE, WS can be CLOSING after ws.close(), and can be CLOSED
        // if phiws.close() has been called in response to ws.onclose.
        self._pwState = self._pwWS ? self._pwWS.readyState : consts.CLOSED

        // _pwLastEventID is deliberately not cleared as it's used in WS reconnects.
        self._pwInfoFrame = null
        self._pwSseListeners = {}
        self._pwAbortConnectors()

        if (oldState !== self._pwStaet) {
          if (self._pwState === consts.CLOSING) {
            self.pwLog(['close($, $): WebSocket closing', code, reason])
          } else if (self._pwState === consts.CLOSED) {
            self.pwFire('closed')
          }
        }

        return self
      },

      // addEventListener('', func) == addEventListener('message', func).
      addEventListener: function (msgEvent, func, cx) {
        self.pwOn(root.typeToEvent(msgEvent), func, cx)
        return self
      },

      removeEventListener: function (msgEvent, func, cx) {
        self.pwOff(root.typeToEvent(msgEvent), func, cx)
        return self
      },

      // This will call newMessage, then 'message:EVENT' handlers with the
      // event object's data property set to 'the data':
      //
      //   var e = new MessageEvent('EVENT')
      //   // Necessary because e.data is read-only.
      //   Phiws.forceSet(e, 'data', 'the data')
      //   phiws.dispatchEvent(e)
      dispatchEvent: function (e) {
        self._pwFireMessage(e)
        return self
      },

      /**
       * Phiws Methods
       */

      get pwBackends()      { return self._pwBackends.concat([]) },
      get pwOptions()       { return root.extend({}, self._pwOptions) },
      get pwWebSocket()     { return self._pwWS },
      get pwEventSource()   { return self._pwSSE },
      get pwIsOpen()        { return self._pwState === consts.OPEN },
      get pwLastEventID()   { return self._pwLastEventID },
      get pwLastEventId()   { return self._pwLastEventID },

      pwBackend: function (index) {
        return root.extend({}, self._pwBackends[index])
      },

      pwOption: function (name) {
        return self._pwOptions[name]
      },

      // pwLog('msg', 'error', e)
      // pwLog(['formatted $ msg', 'arg'], ...)
      pwLog: function (msg, level, data) {
        if (self.pwOption('log') && console) {
          if (msg && typeof msg === 'object') {
            msg = root.format(msg.shift(), msg)
          }

          console[level || 'log']('Phiws: ' + (msg || ''))

          if (data != null) {
            // Firebug doesn't display an entry for scalars.
            if (!console.timeline && (typeof data.length !== 'number' || typeof data !== 'object')) {
              data = [data]
            }

            console.dir(data)
          }
        }

        return self
      },

      pwEventHandlers: function (event) {
        var method = self['on' + event.toLowerCase()]
        var list = self._pwEvents[event] || []
        method && list.unshift(method)
        return list
      },

      pwFire: function (event, args) {
        var funcs = self.pwEventHandlers(event)
        self.pwLog(['$$($): $ handlers', funcs.length ? '  * ' : '    ', event, root.formatArgs(args), funcs.length])

        try {
          return root.fireAll.call(self, funcs, args)
        } catch (e) {
          self.pwLog(['$: exception in a handler', event], 'error', e)
        }
      },

      pwFirer: function (event) {
        return function () {
          self.pwFire(event, arguments)
        }
      },

      // Doesn't check for duplicate handlers.
      pwOn: function (event, func, cx) {
        func = func.bind(cx || self)
        func._pwCX = cx

        self._pwEvents[event] || (self._pwEvents[event] = [])
        self._pwEvents[event].push(func)

        self._pwListenToSseType(event)
        return self
      },

      _pwListenToSseType: function (event) {
        var type = (event.match(/^message:(.+)$/) || [])[1]

        if (self._pwSSE && type && type !== consts.DEFAULT_EVENT && !self._pwSseListeners[type]) {
          self.pwLog(['_pwListenToSseType($): registering a message listener for new event type ($)', event, type])
          self._pwSseListeners[type] = true
          self._pwSSE.addEventListener(type, self.pwFirer('sseMessage'))
        }
      },

      // This method doesn't affect handlers set with phiws.onmessage = function ...
      //
      // pwOff(event, func) - remove handler from specific event (cx is ignored)
      // pwOff(null, func) - remove handler from all events (cx is ignored)
      // pwOff(null/event, null, cx) - remove all handlers of cx from all/specific event
      pwOff: function (event, func, cx) {
        if (!func && !cx) {
          root.fail('pwOff: neither func nor cx are given')
        } else if (event) {
          var filtered = []

          root.each(self._pwEvents[event], function (handler) {
            if ( func ? handler !== func : handler._pwCX !== cx ) {
              filtered.push(handler)
            }
          })

          if (filtered.length !== self._pwEvents[event].length) {
            self._pwEvents[event] = filtered
          }
        } else {
          for (var name in self._pwEvents) {
            self.pwOff(name, func, cx)
          }
        }

        return self
      },

      pwEasyAliases: function () {
        for (var name in self) {
          var alias = name.replace(/^pw([A-Z])/, function (prefix, ch) {
            return ch.toLowerCase()
          })

          if (alias !== name && !self.hasOwnProperty(alias)) {
            Object.defineProperty(self, alias, Object.getOwnPropertyDescriptor(self, name))
          }
        }

        return self
      },

      _pwInit: function (url, opt) {
        self._pwOptions = root.extend({}, self._pwDefaults, opt)
        self._pwBackends = self._pwNormalizeOptions(url, opt)
        self.pwFire('normalizedBackends', [self._pwBackends])
        self.pwLog('_pwNormalizeOptions', null, self._pwBackends)
        self.pwOption('easyAliases') && self.pwEasyAliases()
        self.pwOption('autoConnect') && self.pwConnect()
      },

      _pwNormalizeOptions: function (backends, defaults) {
        var res = []

        root.each(backends, function (backend) {
          if (typeof backend !== 'object') {
            backend = {url: backend}
          }

          res.push(root.extend({}, self._pwDefaults, defaults, backend))
        })

        return res
      },

      pwConnect: function () {
        if (self.readyState === consts.CLOSED) {
          self._pwState = consts.CONNECTING
          self.pwFire('connecting')
          // Defer in case somebody calls this on the UI thread.
          setTimeout(function () { self._pwTryBackend(0) }, 0)
        } else {
          root.fail('pwConnect: invalid readyState')
        }

        return self
      },

      _pwTryBackend: function (index) {
        if (self._pwState !== consts.CONNECTING) {
          return self.pwLog(['_pwTryBackend($): readyState changed, attempts aborted', index], 'info')
        }

        var conn = self._pwMakeConnector(index)

        if (!conn) {
          self.pwLog(['_pwTryBackend($): tried all backends, giving up', index], 'error')
          self.pwFire('backendsFailure')
          return self.close()
        }

        conn.clearTimeout()
        self._pwAbortConnectors()

        self._pwConnectors.push(conn)
        self._pwConnTimer = setTimeout(conn.timeout, conn.options.connectTimeout)

        self._pwOptions = conn.options
        var url = conn.options.url

        try {
          if (root.isWebSocketURL(url)) {
            var param = conn.options.lastEventIdParameter
            if (param && self._pwLastEventID) {
              url += (url.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(param) + '=' + encodeURIComponent(self._pwLastEventID)
            }

            self.pwLog(['_pwTryBackend($): trying WebSocket at $', index, url], 'info')
            var ws = conn.native = new WebSocket(url, conn.options.protocols || [])
            ws.onopen = ws.onerror = conn.handler
            self.pwFire('wsConnecting', [conn, ws])
          } else {
            self.pwLog(['_pwTryBackend($): trying Server-Sent Events at $', index, url], 'info')
            var sse = conn.native = new EventSource(url, conn.options)
            sse.onopen = sse.onerror = conn.handler
            self.pwFire('sseConnecting', [conn, sse])
          }
        } catch (e) {
          // Timeout will catch it but we can skip the wait.
          if (typeof e !== 'object') {
            e = new Error(e)
          }

          root.forceSet(e, 'type', 'error')
          conn.handler(e)
        }
      },

      _pwAbortConnectors: function (except) {
        var conn

        while (conn = self._pwConnectors.pop()) {
          if (conn !== except && conn.native) {
            conn.native && conn.native.close()
          }
        }
      },

      _pwMakeConnector: function (index) {
        var options = self._pwBackends[index]
        if (!options) { return }

        options.index = index

        var conn = {
          index: index,
          lock: self._pwConnLock++,
          options: options,
          native: null,   // WebSocket or EventSource

          get isLast() {
            return self._pwBackends.length <= conn.index + 1
          },

          log: function (msg, level, data) {
            self.pwLog(['backend($): $', conn.index, msg], level || 'info', data)
          },

          // 1. Indicates if no other handlers were called before this function.
          // 2. If so, makes sure subsequent calls will fail, of this connector and
          //    all others that were created before unlocking.
          unlock: function () {
            if (self._pwConnLock === conn.lock + 1) {
              return ++self._pwConnLock
            }
          },

          clearTimeout: function () {
            clearTimeout(self._pwConnTimer)
            self._pwConnTimer = null
          },

          timeout: function () {
            if (!conn.unlock()) {
              return conn.log('timeout out of order, ignored')
            }

            conn.clearTimeout()
            conn.log('timeout', 'warn')
            self.pwFire('connectTimeout', [conn])
            self._pwTryBackend(conn.index + 1)
          },

          handler: function (e) {
            if (!conn.unlock()) {
              return conn.log('event(' + e.type + ') out of order, ignored')
            }

            conn.clearTimeout()

            if (e.type === 'error') {
              conn.log('connection failure', 'warn', e)
              self.pwFire('connectError', [conn, e])
              var delay = conn.isLast ? 0 : conn.options.connectDelay
              setTimeout(function () { self._pwTryBackend(conn.index + 1) }, delay)
            } else if (e.type === 'open') {
              self._pwConnected(e, conn)
            } else {
              root.fail('pwConnector.handler(' + e.type + '): unexpected event')
            }
          },
        }

        return conn
      },

      _pwConnected: function (e, conn) {
        var native = conn.native

        if (native.readyState !== consts.OPEN || self._pwState !== consts.CONNECTING) {
          root.fail("_pwConnected: invalid readyState")
        }

        conn.log('connected')

        self._pwConnLock++
        self._pwAbortConnectors(conn)
        self._pwState = consts.OPEN
        self.pwFire('connected', [conn])

        if (native.toString() === "[object WebSocket]") {
          self._pwWS = native
          var prefix = 'ws'
          native.onclose = self.pwFirer('wsClose')
        } else {
          var prefix = 'sse'
          self._pwSSE = native

          var stopEvent = self.pwOption('stopSseEvent')
          stopEvent && self._pwListenToSseType('message:' + stopEvent)

          root.each(self._pwEvents, function (funcs, event) {
            self._pwListenToSseType(event)
          })
        }

        // SSE: fired on reconnect (first connect event was handled by Phiws).
        // WS: not fired (at least not expect to fire).
        native.onopen = self.pwFirer(prefix + 'Open')
        native.onmessage = self.pwFirer(prefix + 'Message')
        // SSE: upon reconnect or close (204 No Content). WS: unclean close.
        native.onerror = self.pwFirer(prefix + 'Error')

        self.pwFire('open', [e, self])
      },

      _pwReconnectWS: function (e, tryNext) {
        self.pwFire('reconnect', [e, self._pwWS])
        var delay = self.pwOption('reconnectDelay')

        if (delay.length) {
          delay = delay[0] + Math.floor(Math.random() * (delay[1] - delay[0] + 1))
        }

        if (delay != false) {
          var lock = self._pwConnLock++

          setTimeout(function () {
            if (self.pwIsOpen && self._pwConnLock === lock + 1) {
              self._pwState = consts.CONNECTING
              self._tryBackend(self.pwOption('index') + !!tryNext)
            }
          }, delay)
        }
      },

      /**
      * Phiws Event Handlers
      */

      onwsclose: function (e) {
        if (self._pwWS === e.target) {
          var reconnect = self.pwOption('reconnectCodes').indexOf(e.code) >= 0
            ? 're' : (self.pwOption('tryNextBackendCodes').indexOf(e.code) >= 0
            ? 'dis' : false)

          self.pwLog(['onwsclose($, $): $', e.code, e.reason, e.wasClean ? 'clean' : 'not clean'], 'info', e)

          if (reconnect) {
            self.pwLog(['onwsclose($): $connecting due to WebSocket server indication', e.code, reconnect], 'info')
            self._pwReconnectWS(e, reconnect === 'n')
          } else {
            self.close()
          }
        }
      },

      onwserror: function (e) {
        if (self._pwWS === e.target && self.pwIsOpen) {
          self.pwFire('error', [e, self._pwWS])
          self.close()
        }
      },

      onsseerror: function (e) {
        if (self._pwSSE === e.target && self.pwIsOpen) {
          if (self._pwSSE.readyState === consts.CONNECTING) {
            // This is /probably/ a reconnect.
            // "Clients will reconnect if the connection is closed; [...]"
            self.pwFire('reconnect', [e, self._pwSSE])

            // But this detection is unreliable and will report actual stops as
            // reconnects:
            // - As per spec, the only ways to stop SSE is by sending HTTP 204 code,
            //   but this is only available when no content has been sent yet, or
            //   by failing the request in some way, which again is not available
            //   if something has been sent (since you can't change HTTP code).
            // - If server does fail or send 204 upon connection - this handler
            //   will never be reached because EventSource won't connect at all.
            // - Phiws/PHP sends a very big 'retry' value if it wants client
            //   to stop; naturally, browser reflects this as CONNECTING readyState
            //   and there's no way to access this value via EventSource API.
            //   To work around this, Phiws/PHP sends a special event to signal its
            //   intention but other implementations won't do that.
          } else {
            self.pwFire('error', [e, self._pwSSE])
            self.close()
          }
        }
      },

      onsseopen: function (e) {
        if (self._pwSSE === e.target) {
          // It was a reconnect since first onopen is handled by _pwTryBackend().
          self.pwFire('open', [e, self]);
        }
      },

      onwsmessage: function (e) {
        if (self._pwWS === e.target) {
          if (typeof e.data === 'string') {
            self.pwLog('onwsmessage: new WebSocket info frame received', null, e)

            try {
              var info = JSON.parse(e.data)
            } catch (e) {
              self.pwLog('onwsmessage: error parsing payload as JSON, frame ignored', 'error', e)
              return
            }

            self._pwInfoFrame = info.hasData ? info : null

            if (info.hasData) {
              self.pwLog(['onwsmessage($): waiting for the data frame to dispatch the message', info.type])
            } else {
              // A message without data (like empty string).
              root.forceSet(e, 'data', '')
              self._pwFireMessage(e, info)
            }
          } else {
            self.pwLog(['onwsmessage($): data frame received, dispatching message', self._pwInfoFrame.type], null, self._pwInfoFrame)
            self._pwFireMessage(e, self._pwInfoFrame)
          }
        }
      },

      onssemessage: function (e) {
        if (self._pwSSE === e.target) {
          var prefixMode = self.pwOption('ssePrefixWithEventType')

          if (prefixMode) {
            var pos = e.data.indexOf('\n')
            var type = pos < 0 ? e.data : e.data.substr(0, pos)

            if (prefixMode !== 'force' && (type.length > 100 || /[^\w-.:]/.test(type))) {
              self.pwLog('onssemessage: ssePrefixWithEventType is enabled on the client but received type looks strange, assuming it\'s part of the data', 'warn', type)
            } else {
              self.pwLog(['onssemessage: ssePrefixWithEventType is enabled, inferred type ($)', type])
              root.forceSet(e, 'type', type || consts.DEFAULT_EVENT)
              root.forceSet(e, 'data', e.data.substr(type.length + 1))
            }
          }

          if (e.type === self.pwOption('stopSseEvent')) {
            self.pwLog(['onssemessage($): server indicated to stop', e.type])
            self.close()    // server-side assisted simulation of "clean close"
            return
          }

          self.pwLog(['onssemessage($): new Server-Sent Event received', e.type], null, e)
          self._pwFireMessage(e)
        }
      },

      _pwFireMessage: function (e, info) {
        info && root.extend(e, info)
        e = self._pwNormMessageEvent(e)

        if (typeof e.data === 'object') {
          var length = e.data.size || e.data.byteLength

          if (length <= self.pwOption('longestString')) {
            return root.readAsText(function (str) {
              root.forceSet(e, 'data', str)
              self._pwFireNormalizedMessage(e)
            })
          } else {
            self.pwLog(['_pwFireMessage($): not reading message data as text - too big ($ bytes), keeping binary object', e.type, length], 'info')
          }
        }

        self._pwFireNormalizedMessage(e)
      },

      _pwNormMessageEvent: function (e) {
        if (!e.type.length) {
          root.forceSet(e, 'type', consts.DEFAULT_EVENT)
        }

        if (typeof e.lastEventId === 'undefined') {
          try {
            e.lastEventId = null
          } catch (e) { }
        }

        var oldStopPr = e.stopPropagation

        root.forceSet(e, 'stopPropagation', function () {
          e._pwStoppedPropagation = true
          return oldStopPr.apply(e, arguments)
        })

        return e
      },

      _pwFireNormalizedMessage: function (e) {
        if (typeof e.data === 'string' && !e.data.length && self.pwOption('ignoreEmptyMessages')) {
          return self.pwLog(['_pwFireNormalizedMessage($): ignoring empty message', e.type], 'info')
        }

        self._pwLastEventID = e.lastEventId
        var args = [e, self]
        self.pwFire('newMessage', args)

        if (e._pwStoppedPropagation) {
          return self.pwLog(['_pwFireNormalizedMessage($): a newMessage handler has stopped propagation, not firing message[:EVENT]', e.type], 'info')
        }

        var funcs = self.pwEventHandlers(root.typeToEvent(e.type))

        root.each(funcs, function (func) {
          if (func.apply(self, args) === false) {
            e.stopPropagation()
          }

          return !e._pwStoppedPropagation
        })
      },
    }

    self._pwInit(url, opt)
    root.defineConstants(self)
    return self
  }

  root.fail = function (msg) {
    throw 'Phiws: ' + msg
  }

  // If a is null/undefined it's not iterated, otherwise it's converted to array.
  root.each = function (a, func, cx) {
    if (a) {
      if (typeof a !== 'object') {
        a = ([]).concat(a)
      }

      if (typeof a.length === 'number') {
        for (var i = 0; i < a.length; i++) {
          var res = func.call(cx, a[i], i)
          if (res != null) { return res }
        }
      } else {
        for (var i in a) {
          var res = func.call(cx, a[i], i)
          if (res != null) { return res }
        }
      }
    }
  }

  root.extend = function () {
    var res = arguments[0]

    root.each(arguments, function (item, i) {
      if (i > 0) {
        for (var key in item) {
          res[key] = item[key]
        }
      }
    })

    return res
  }

  root.fireAll = function (funcs, args) {
    return root.each(funcs, function (func) {
      return func.apply(this, args)
    }, this)
  }

  root.readAsText = function (blob, done) {
    switch (blob + '') {
    case '[object Blob]':
      var fr = new FileReader
      fr.onloadend = function () { done(fr.result) }
      return fr.readAsText(b)
    case '[object ArrayBuffer]':
      done( (new TextDecoder).decode(new DataView(blob)) )
    default:
      root.fail("readAsText: unknown type: " + blob)
    }
  }

  root.format = function fmt(str, args) {
    return str.replace(/\$/g, function () {
      return args.shift()
    })
  }

  root.formatArgs = function (args) {
    var res = []

    root.each(args, function (arg) {
      res.push(root.formatArg(arg))
    })

    return res.join(', ')
  }

  root.formatArg = function (value) {
    var type = typeof value

    if (type === 'object') {
      if (typeof value.length === 'number') {
        return 'array[' + value.length + ']'
      }
    } else if (type === 'function') {
      return 'func'
    }

    value += ''

    if (value.length > 20) {
      value = value.substr(0, 17) + '[' + value.length + ']…'
    }

    switch (type) {
    case 'string':
      return '"' + value + '"'
    default:
      return value.replace(/^\[object (\w+)\]$/, '$1')
    }
  }

  root.typeToEvent = function (msgEvent) {
    var event = 'message'
    msgEvent += ''

    if (msgEvent.length && msgEvent !== consts.DEFAULT_EVENT) {
      event += ':' + msgEvent
    }

    return event
  }

  // Returns e.
  root.forceSet = function (e, propName, value) {
    return Object.defineProperty(e, propName, {
      configurable: true,
      enumerable: true,
      writable: true,
      value: value,
    })
  },

  root.isWebSocketURL = function (url) {
    return /^wss?:/i.test(url)
  }

  root.defineConstants = function (obj, list) {
    root.each(list || consts, function (value, name) {
      Object.defineProperty(obj, name, {
        writable: false,
        value: value,
      })
    })
  }

  // Deliberately not cloned, just in case they have to be changed.
  root.constants = consts
  root.defineConstants(root)
})();
