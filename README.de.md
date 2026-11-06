# NoticeBoard

Deutsch | [English](README.md)

IP-Symcon-Bibliothek von Ueli Schweizer / Cassanis Automation.

| Modul | Beschreibung |
|---|---|
| [NoticeBoard](NoticeBoard/README.de.md) | Kachel mit aktiven Status und Ereignismeldungen nach Priorität, gespeist von Scripts oder Regeln für Bedingungen, veraltete Werte und Instanzfehler |

## Voraussetzungen

IP-Symcon ab Version 8.2. Ab 9.1 kann die Kachel zusätzlich im Vollbild-Modus angezeigt werden.

## Installation

Über den Module Store oder im Module Control mit der URL
`https://github.com/wowbagger60/NoticeBoard`.

## Entwicklung

Die Modullogik ist mit einem PHP-Test in einer simulierten Symcon-Umgebung
abgedeckt, die Kachel mit einem DOM-Test (benötigt jsdom ausserhalb der
Bibliothek):

```
php tests/run.php
NODE_PATH=/pfad/zu/node_modules node tests/tile.cjs
```

Die Tests simulieren den Symcon-Kernel und ersetzen keinen Test in einer echten
IP-Symcon-Installation.

## Unterstützung

Dieses Modul ist kostenlos. Schenkungen als Unterstützung für den Autor werden
gerne angenommen: PayPal an ueli.schweizer@cassanis.fr

## Lizenz

MIT, siehe [LICENSE](LICENSE).
