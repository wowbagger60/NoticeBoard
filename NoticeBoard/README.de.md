# NoticeBoard

Deutsch | [English](README.md)

Modul für die IP-Symcon Kachel-Visualisierung von Ueli Schweizer / Cassanis Automation.
Präfix: `NBOARD`.

NoticeBoard zeigt aktive **Status** und einzelne **Messages** gemeinsam in einer
scrollbaren Kachel, sortiert nach frei definierbaren Stufen mit Farbe und
Priorität. Einträge entstehen über die PHP-API oder automatisch über Regeln:
Bedingungen über Variablen, Überwachung der Aktualisierung von Variablen und
Überwachung von Instanzfehlern.

> NoticeBoard zeigt Meldungen an. Es versendet keine Push-Nachrichten.

## Inhaltsverzeichnis

1. [Voraussetzungen](#1-voraussetzungen)
2. [Installation und erster Test](#2-installation-und-erster-test)
3. [Status und Messages](#3-status-und-messages)
4. [Konfiguration](#4-konfiguration)
5. [Regeln](#5-regeln)
6. [Benachrichtigung](#6-benachrichtigung)
7. [PHP-API](#7-php-api)
8. [Ausgabevariablen](#8-ausgabevariablen)
9. [Betrieb](#9-betrieb)
10. [Unterstützung](#10-unterstützung)
11. [Änderungen](#11-änderungen)

## 1. Voraussetzungen

- IP-Symcon ab Version 8.2.
- NoticeBoard wird als normale Kachel angezeigt. Ab Version 9.1 kann es
  zusätzlich im Vollbild-Modus angezeigt werden.

## 2. Installation und erster Test

1. Bibliothek über den Module Store oder das Module Control installieren und eine
   NoticeBoard-Instanz anlegen.
2. Instanz in der Visualisierung als Kachel anzeigen. Ohne Einträge erscheint der
   Text «Keine aktiven Meldungen».
3. In einem Script einen Status und eine Message erzeugen:

```php
$BoardID = 12345; // ID der NoticeBoard-Instanz

NBOARD_SetStatusItem($BoardID, 'heating.failure', 'Alarm', 'Wärmepumpe gestört', 1);
NBOARD_AddMessage($BoardID, 'Information', 'Wasserversorgung umgeschaltet', 1, 0, 'water');
```

4. Den Status wieder aufheben und die Message löschen:

```php
NBOARD_ClearStatusItem($BoardID, 'heating.failure');
NBOARD_DeleteMessagesByIdentifier($BoardID, 'water');
```

Ohne Script: Im Formular unter «Bedingungen», «Aktualisierungsüberwachung» oder
«Instanzüberwachung» eine Regel anlegen (siehe [Regeln](#5-regeln)).

## 3. Status und Messages

Ein **Status** beschreibt einen andauernden Zustand. Er hat einen Schlüssel und
bleibt aktiv, bis er aufgehoben wird. Erneutes Setzen mit demselben Schlüssel
aktualisiert ihn; unveränderte Wiederholungen ändern nichts. Status haben keinen
Papierkorb und laufen nie automatisch ab.

Eine **Message** beschreibt ein einzelnes Ereignis. Sie bleibt bis zum Ablauf
ihrer Aufbewahrung oder bis zum Löschen über den Papierkorb sichtbar.
`Sequence` ist ihr eindeutiger technischer Schlüssel. `Identifier` ist eine
optionale, mehrfach verwendbare fachliche Kennung. Messages ohne Identifier
lassen sich nur über ihre Sequence löschen.

Ein Klick auf einen Eintrag öffnet sein Ziel per `openObject()`. **Ohne Ziel**
(«Kein(e)», Symcon-Objekt-ID 1) bewirkt ein Klick nichts.

Texte werden als Klartext angezeigt, nicht als HTML. Schlüssel, Stufen-IDs und
Identifier werden ohne Beachtung der Gross-/Kleinschreibung verglichen.

## 4. Konfiguration

| Einstellung | Bedeutung |
|---|---|
| Stufen | `ID`, ganzzahlige `Priority` ab 1 (1 = höchste), `Color`, «Benachrichtigen» |
| Gruppierung | Status zuerst, Messages zuerst oder gemischt |
| Statussortierung | Priorität, neueste oder älteste zuerst |
| Meldungssortierung | Neueste, älteste oder Priorität zuerst |
| Datum und Zeit | anzeigen oder nicht; PHP-Datumsformat, Standard `d.m. H:i` |
| Text ohne aktive Einträge | frei wählbar |
| Zusammenfassungsvariablen | optional, siehe [Ausgabevariablen](#8-ausgabevariablen); Standard aus |
| Standard-Aufbewahrung für Meldungen | Tage, Standard 30; 0 = unbegrenzt |
| Aufbewahrung der History | Tage, Standard 365; 0 = unbegrenzt |
| Maximale History-Einträge | Standard 1000; 0 = unbegrenzt |

Die Standardstufen werden beim Anlegen in der Sprache von IP-Symcon benannt
(z. B. Alarm, Warnung, Information). Die IDs sind frei änderbar; Scripts
verwenden die konfigurierte ID. Einträge mit einer nicht mehr vorhandenen Stufe
bleiben sichtbar, grau und mit niedrigster Priorität.

Noch sichtbare Messages werden von der History-Bereinigung nie entfernt; die
maximale Anzahl ist daher eine weiche Grenze.

## 5. Regeln

Alle drei Regellisten haben eine Spalte «Aktiv». Deaktivierte Regeln werden
ignoriert und ihre Status aufgehoben. Ungültige Regeln (unbekannte Stufe, leerer
Text, fehlende Objekte) werden übersprungen und im Meldungsfenster aufgeführt;
die Instanz zeigt dann den Status 202. Die übrigen Regeln laufen weiter.

### Platzhalter

| Platzhalter | Inhalt |
|---|---|
| `{name}` | Name der Variable bzw. Instanz |
| `{parent}` | Name des übergeordneten Objekts, z. B. des Geräts |
| `{value}` | formatierter Wert der Variable, mit Profil |
| `{age}` | Zeit seit der letzten Aktualisierung (Aktualisierungsüberwachung) |
| `{status}` | Statuscode der Instanz (Instanzüberwachung) |
| `{statustext}` | Klartext des Statuscodes in der Sprache von IP-Symcon (Instanzüberwachung) |

### Bedingungen

Die Bedingung wird mit dem Symcon-Bedingungseditor festgelegt. Ausgewertet wird
bei jeder Aktualisierung einer beteiligten Variable und spätestens jede Minute
(für Datums- und Zeitbedingungen).

| Spalte | Bedeutung |
|---|---|
| Modus «Status» | aktiv, solange die Bedingung erfüllt ist |
| Modus «Meldung» | genau eine Message beim Wechsel auf «erfüllt» |
| Verzögerung | Sekunden, die die Bedingung ununterbrochen erfüllt sein muss |
| Platzhalter-Variable | Quelle für `{name}`, `{parent}` und `{value}`; ohne Auswahl die erste Variable der Bedingung |
| Ziel | Klickziel in der Kachel; ohne Auswahl kein Sprung |

Ändert sich bei einem Status nur der Text, z. B. durch `{value}`, wird er still
aktualisiert, ohne History-Eintrag. Die Platzhalter-Variable muss nicht Teil der
Bedingung sein; ihre Aktualisierungen halten `{value}` aktuell. Der Startzeitpunkt
einer laufenden Verzögerung wird gespeichert und übersteht einen Neustart.

Messages einer Regel erhalten den Regelschlüssel als Identifier und lassen sich
mit `NBOARD_DeleteMessagesByIdentifier()` gemeinsam löschen.

Beispiele:

| Bedingung | Text | Modus | Verzögerung |
|---|---|---|---|
| Kühlschrank Temperatur > 8 | `{parent} zu warm: {value}` | Status | 600 |
| Phase A Spannung < 198 V | `Spannung Phase A: {value}` | Status | 10 |
| Fenster Bad = offen UND Regen = ja | `Bad: Fenster offen, es regnet` | Status | 0 |
| Türklingel = true | `Es hat geklingelt` | Meldung | 0 |

Abläufe mit Gedächtnis, z. B. «Waschmaschine war an und ist jetzt fertig»,
lassen sich mit einer einfachen Bedingung nicht abbilden. Dafür ist die PHP-API
gedacht.

### Aktualisierungsüberwachung

Ein Status erscheint, wenn eine Variable länger als das maximale Alter (Minuten)
nicht aktualisiert wurde, und verschwindet bei der nächsten Aktualisierung.
Grundlage ist der von IP-Symcon gespeicherte Zeitpunkt der letzten
Aktualisierung; Status und `{age}` überstehen deshalb einen Neustart. `{age}`
läuft in der Kachel mit, ohne die History zu füllen.

### Instanzüberwachung

Ein Status erscheint, solange eine Instanz einen Fehlerstatus (Code ab 200)
meldet. Ausgewertet wird bei jeder Statusänderung der Instanz und spätestens jede
Minute. **Inaktive Instanzen (Code 104) gelten nicht als Fehler** und werden
nicht angezeigt. Eine Verzögerung unterdrückt kurze Unterbrechungen, z. B.
während eines Reconnects.

`{statustext}` stammt aus dem Konfigurationsformular der überwachten Instanz und
wird mit den dort mitgelieferten Übersetzungen übersetzt. Fehlt eine
Übersetzung, erscheint der Originaltext; fehlt der Text ganz, der Code. Das
Formular wird nur gelesen, wenn ein Fehler vorliegt und der Text `{statustext}`
enthält, und danach je Instanz und Code zwischengespeichert.

### Schlüssel der Regel-Status

Regel-Status verwenden reservierte Schlüssel: `condition:…`,
`stale:<VariablenID>` und `instance:<InstanzID>`. Scripts können solche
Schlüssel weder setzen noch aufheben. Bei Bedingungen wird der Schlüssel aus
Bedingung und Modus gebildet: Text, Stufe, Ziel und Verzögerung lassen sich
ändern, ohne dass der Status neu entsteht; eine geänderte Bedingung gilt als
neue Regel.

## 6. Benachrichtigung

NoticeBoard versendet selbst nichts, kann aber ein eigenes Script aufrufen, das
die Benachrichtigung übernimmt, z. B. per Push, Telegram oder E-Mail. Das Script
wird unter «Benachrichtigung» ausgewählt.

Aufgerufen wird es bei:

| `NBOARD_EVENT` | Ereignis |
|---|---|
| `set` | neuer Status |
| `changed` | protokollierte Statusänderung (Stufe, Ziel oder Text über die API) |
| `cleared` | aufgehobener Status |
| `message` | neue Message |

Nicht gemeldet werden stille Textänderungen von Regel-Status (`{value}`,
`{age}`), unveränderte Wiederholungen und das Löschen von Messages. Aktive Status
bleiben über einen Neustart erhalten und werden danach nicht erneut gemeldet.

Gefiltert wird nur über die Spalte «Benachrichtigen» der Stufen (Standard: an).
Alles Weitere lässt sich im Script entscheiden.

Das Script läuft asynchron, einmal je Ereignis und erst nachdem NoticeBoard
seine Daten gespeichert hat. Gleichzeitige Ereignisse gehen deshalb nicht
verloren, NoticeBoard wartet nie auf das Script, und das Script darf selbst
`NBOARD_`-Funktionen aufrufen. Die Reihenfolge gleichzeitiger Aufrufe ist nicht
garantiert.

| Parameter | Inhalt |
|---|---|
| `$_IPS['NBOARD_INSTANCE']` | ID der NoticeBoard-Instanz |
| `$_IPS['NBOARD_EVENT']` | `set`, `changed`, `cleared` oder `message` |
| `$_IPS['NBOARD_TYPE']` | `status` oder `message` |
| `$_IPS['NBOARD_SOURCE']` | `script`, `condition`, `stale` oder `instance` |
| `$_IPS['NBOARD_LEVEL']` | Stufen-ID |
| `$_IPS['NBOARD_PRIORITY']` | Priorität der Stufe; 0, wenn die Stufe nicht mehr existiert |
| `$_IPS['NBOARD_MESSAGE']` | Text, Platzhalter bereits eingesetzt |
| `$_IPS['NBOARD_KEY']` | Schlüssel des Status; leer bei Messages |
| `$_IPS['NBOARD_IDENTIFIER']` | Identifier der Message; leer bei Status |
| `$_IPS['NBOARD_SEQUENCE']` | Sequence des History-Eintrags; bei Messages für `NBOARD_DeleteMessage()` verwendbar |
| `$_IPS['NBOARD_TARGET']` | Ziel-ID; 1 = kein Ziel |
| `$_IPS['NBOARD_ITEM']` | vollständiger History-Eintrag als JSON |

Beispiel mit der Benachrichtigung der Kachel-Visualisierung:

```php
<?php
if ($_IPS['SENDER'] !== 'RunScript' || !isset($_IPS['NBOARD_EVENT'])) {
    return;
}
if ($_IPS['NBOARD_EVENT'] === 'cleared') {
    return; // Aufhebungen nicht melden
}

$VisuID = 12345; // ID der Kachel-Visualisierung
$Type = (int) $_IPS['NBOARD_PRIORITY'] === 1 ? 'Alert' : 'Info';
VISU_PostNotification($VisuID, $_IPS['NBOARD_LEVEL'], $_IPS['NBOARD_MESSAGE'], $Type, (int) $_IPS['NBOARD_TARGET']);
```

## 7. PHP-API

In IP-Symcon sind alle Argumente zu übergeben.

| Aufruf | Rückgabe und Verhalten |
|---|---|
| `NBOARD_SetStatusItem($InstanceID, $Key, $LevelID, $Message, $TargetID)` | `bool`: Status setzen oder ändern |
| `NBOARD_ClearStatusItem($InstanceID, $Key)` | `bool`: Status aufgehoben; `false`, wenn nicht vorhanden |
| `NBOARD_AddMessage($InstanceID, $LevelID, $Message, $TargetID, $RetentionDays, $Identifier)` | `int`: neue Sequence; 0 bei ungültigen Daten |
| `NBOARD_DeleteMessage($InstanceID, $Sequence)` | `bool`: Message gefunden und ausgeblendet |
| `NBOARD_DeleteMessagesByIdentifier($InstanceID, $Identifier)` | `int`: Anzahl neu ausgeblendeter Messages; ein leerer Identifier löscht nichts |
| `NBOARD_GetCurrentItems($InstanceID)` | JSON: aktuelle, sortierte Einträge |
| `NBOARD_GetHistory($InstanceID)` | JSON: History, älteste zuerst |
| `NBOARD_GetCount($InstanceID, $LevelID)` | `int`: Anzahl aktiver Einträge; `''` = alle Stufen |
| `NBOARD_GetHighestLevel($InstanceID)` | `string`: höchste aktive Stufe; `''` = keine |
| `NBOARD_ClearHistory($InstanceID)` | löscht die gesamte History, auch sichtbare Messages; aktive Status bleiben |

- `$TargetID`: `1` = kein Ziel (Symcon-Standard; `0` wird ebenfalls als kein Ziel
  behandelt), sonst eine existierende Objekt-ID.
- `$RetentionDays`: `0` = Standard der Instanz, `-1` = unbegrenzt, sonst Tage.
- Ungültige Daten (leerer Text, unbekannte Stufe, fehlendes Ziel) liefern
  `false` bzw. 0 und eine Warnung.
- Gleichzeitige Zugriffe werden je Instanz nacheinander verarbeitet. Nach fünf
  Sekunden Wartezeit wird eine Exception ausgelöst.

## 8. Ausgabevariablen

| Variable | Inhalt |
|---|---|
| `CurrentItems` | JSON: aktuelle, sortierte Einträge |
| `History` | JSON: Messages und Statusereignisse |
| `ActiveCount` | Anzahl aktiver Einträge (nur mit Zusammenfassungsvariablen) |
| `HighestLevel` | höchste aktive Stufe (nur mit Zusammenfassungsvariablen) |

Die Zusammenfassungsvariablen sind standardmässig ausgeschaltet, weil Variablen
bei manchen Lizenzen mitzählen. Dieselben Werte liefern `NBOARD_GetCount()` und
`NBOARD_GetHighestLevel()` ohne zusätzliche Variablen.

Alle Variablen sind schreibgeschützt und werden nur bei geändertem Inhalt
geschrieben, `History` immer vor `CurrentItems`. Ein auf `CurrentItems`
reagierendes Script findet die zugehörigen History-Daten so bereits vor.

Alle Einträge enthalten `Source` (`script`, `condition`, `stale`, `instance`).
Statusereignisse in der History enthalten `Event` (`set`, `changed`, `cleared`)
und `EventTime`; bei Änderungen zusätzlich `PreviousLevel`, `PreviousMessage`
und `PreviousTargetID`, bei aufgehobenen Status `Resolved` und `Duration` in
Sekunden. Gelöschte Messages behalten ihre Daten mit `Deleted = true` und
`DeletedAt`.

## 9. Betrieb

- Daten werden nur bei Änderungen geschrieben; die Kachel wird nur bei
  geändertem Inhalt aktualisiert.
- Der Wartungstimer läuft höchstens jede Minute und früher, wenn eine
  Verzögerung oder ein Ablauf fällig ist.
- Nach dem Systemstart wird erst ausgewertet, wenn IP-Symcon bereit ist.
- Status, History und Regelzustände liegen in Instanzattributen und sind Teil der
  Symcon-Sicherung. Löschen der Instanz löscht auch ihre Daten.
- Zeitangaben werden in der Zeitzone des Symcon-Servers formatiert.

## 10. Unterstützung

Dieses Modul ist kostenlos. Schenkungen als Unterstützung für den Autor werden
gerne angenommen: PayPal an ueli.schweizer@cassanis.fr

## 11. Änderungen

**2.0 Build 1:** Erste öffentliche Beta-Version.
