<?php namespace Phiws;

interface PluginInterface {
  // If returns true, must define firing($event, $cx, array $args). Global hooks
  // are fired after normal events() handlers.
  function isGlobalHook();

  // List of events (methods) that this plugin is interested in.
  // Others won't be triggered. Handlers of events prefixed with '-' are called 
  // before existing handlers.
  //
  // This would call normalEvent() in order of plugin registration, and call
  // beforeHandler() before handlers registered prior to this plugin:
  //   return ['normalEvent', '-beforeHandler'];
  function events();
}
