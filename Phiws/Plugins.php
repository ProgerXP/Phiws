<?php namespace Phiws;

class Plugins implements \Countable, \IteratorAggregate {
  const STOP = false;

  protected $context;
  protected $plugins = [];
  protected $persistentPlugins = [];
  // Array of event => array of callable.
  protected $hooks = [];
  // Array of callable. $eventName is pushed in front of other arguments (even $cx).
  protected $globalHooks = [];

  // $context - any object/value that will be prepended to all called functions'
  // arguments (including null).
  function __construct($context = null) {
    $this->context = $context;
  }

  function context() {
    return $this->context;
  }

  function addFrom(Plugins $plugins, $newContext = null) {
    foreach ($plugins as $plugin) {
      $this->add($plugin->cloneFor($newContext));
    }

    return $this;
  }

  function add(PluginInterface $plugin, $persistent = false) {
    if (!$this->has($plugin)) {
      $this->{$persistent ? 'persistentPlugins' : 'plugins'}[] = $plugin;
      $this->hook($plugin);
    }

    return $this;
  }

  // Checks for exact instance, not plugin class (some plugins can be customized
  // and perform different functions even when they're of the same class).
  function has(PluginInterface $plugin) {
    return in_array($plugin, $this->plugins, true) or
           in_array($plugin, $this->persistentPlugins, true);
  }

  function hasClass($class) {
    foreach ($this->plugins as $pi) {
      if ($pi instanceof $class) { return $pi; }
    }

    foreach ($this->persistentPlugins as $pi) {
      if ($pi instanceof $class) { return $pi; }
    }
  }

  protected function hook(PluginInterface $plugin) {
    $plugin->isGlobalHook() and $this->addGlobalHook([$plugin, 'firing']);

    foreach ($plugin->events() as $event) {
      $inFront = $event[0] === '-';
      $inFront and $event = substr($event, 1);

      $ref = &$this->hooks[$event];
      $ref or $ref = [];

      $inFront ? array_unshift($ref, [$plugin, $event]) : $ref[] = [$plugin, $event];
    }
  }

  protected function addGlobalHook($func) {
    $this->globalHooks[] = $func;
  }

  function remove(PluginInterface $plugin) {
    foreach ([$this->plugins, $this->persistentPlugins] as &$list) {
      $index = array_search($plugin, $list, true);

      if ($index !== false) {
        unset($list[$index]);
        $this->unhook($this->hooks, $plugin);
        $list = [&$this->globalHooks];
        $this->unhook($list, $plugin);
        break;
      }
    }

    return $this;
  }
      
  protected function unhook(array &$list, PluginInterface $plugin) {
    foreach ($list as &$handlers) {
      foreach ($handlers as $key => $func) {
        if (is_array($func) and $func[0] === $plugin) {
          unset($handlers[$key]);
        }
      }
    }
  }

  function clear() {
    $this->plugins = [];
    $this->hooks = [];
    $this->globalHooks = [];
    foreach ($this->persistentPlugins as $p) { $this->hook($p); }
    return $this;
  }

  // Persistent plugins excluded because they are usually not user-initiated.
  // It's more intuitive to see 0 on a new instance. Compare with:
  //   (new Client)->plugins()->allCount() - returns 3
  #[\ReturnTypeWillChange]
  function count() {
    return count($this->plugins);
  }

  function allCount() {
    return $this->count() + count($this->persistentPlugins);
  }

  #[\ReturnTypeWillChange]
  function getIterator() {
    return new \ArrayIterator($this->plugins);
  }

  protected function hooks($event) {
    $ref = &$this->hooks[$event];
    return $ref ?: [];
  }

  function fire($event, array $args = []) {
    $this->callAll($this->hooks($event), array_merge([$this->context], $args));
    $this->callAll($this->globalHooks, [$event, $this->context, $args]);
  }

  protected function callAll(array $funcs, array $args) {
    foreach ($funcs as $func) {
      if (call_user_func_array($func, $args) === static::STOP) {
        break;
      }
    }
  }
}
