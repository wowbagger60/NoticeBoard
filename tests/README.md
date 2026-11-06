# Standalone tests

Run outside IP-Symcon from the repository root:

```sh
php tests/run.php
NODE_PATH=/path/to/external/node_modules node tests/tile.cjs
```

The PHP test defines a small IPSModuleStrict/SDK stub (including simulated variables, instances and `IPS_IsConditionPassing`); do not execute it as a Symcon script. PHP 8.2 or later is required. The DOM test needs jsdom installed outside the production library.

These tests cover business logic and DOM interactions; they do not emulate the Symcon kernel, the real condition editor format, real parallel threads or pixel layout.
