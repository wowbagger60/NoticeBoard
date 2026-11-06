# NoticeBoard

[Deutsch](README.de.md) | English

IP-Symcon library by Ueli Schweizer / Cassanis Automation.

| Module | Description |
|---|---|
| [NoticeBoard](NoticeBoard/README.md) | Tile showing active statuses and event messages by priority, fed by scripts or rules for conditions, stale values and instance errors |

## Requirements

IP-Symcon 8.2 or later. From 9.1, the tile can also be shown in fullscreen mode.

## Installation

Via the Module Store, or in Module Control with the URL
`https://github.com/wowbagger60/NoticeBoard`.

## Development

The module logic is covered by a PHP test with a simulated Symcon environment,
the tile by a DOM test (requires jsdom outside the library):

```
php tests/run.php
NODE_PATH=/path/to/node_modules node tests/tile.cjs
```

The tests simulate the Symcon kernel and do not replace a test in a real
IP-Symcon installation.

## Support

This module is free of charge. Donations to support the author are gratefully
accepted: PayPal to ueli.schweizer@cassanis.fr

## License

MIT, see [LICENSE](LICENSE).
