# NoticeBoard

[Deutsch](README.de.md) | English

Module for the IP-Symcon tile visualization by Ueli Schweizer / Cassanis Automation.
Prefix: `NBOARD`.

NoticeBoard shows active **statuses** and individual **messages** together in one
scrollable tile, ordered by configurable levels with color and priority. Entries
come from the PHP API or automatically from rules: conditions on variables,
monitoring of variable updates and monitoring of instance errors.

> NoticeBoard displays notices. It does not send push notifications.

## Table of contents

1. [Requirements](#1-requirements)
2. [Installation and first test](#2-installation-and-first-test)
3. [Statuses and messages](#3-statuses-and-messages)
4. [Configuration](#4-configuration)
5. [Rules](#5-rules)
6. [Notification](#6-notification)
7. [PHP API](#7-php-api)
8. [Output variables](#8-output-variables)
9. [Operation](#9-operation)
10. [Support](#10-support)
11. [Changelog](#11-changelog)

## 1. Requirements

- IP-Symcon 8.2 or later.
- NoticeBoard is displayed as a regular tile. From version 9.1, it can also be
  shown in fullscreen mode.

## 2. Installation and first test

1. Install the library via the Module Store or Module Control and create a
   NoticeBoard instance.
2. Show the instance as a tile in the visualization. Without entries, the text
   «No active notices» appears.
3. Create a status and a message in a script:

```php
$BoardID = 12345; // ID of the NoticeBoard instance

NBOARD_SetStatusItem($BoardID, 'heating.failure', 'Alarm', 'Heat pump failure', 1);
NBOARD_AddMessage($BoardID, 'Information', 'Water supply switched over', 1, 0, 'water');
```

4. Clear the status and delete the message again:

```php
NBOARD_ClearStatusItem($BoardID, 'heating.failure');
NBOARD_DeleteMessagesByIdentifier($BoardID, 'water');
```

Without a script: create a rule under «Condition rules», «Update monitoring» or
«Instance monitoring» in the form (see [Rules](#5-rules)).

## 3. Statuses and messages

A **status** describes an ongoing state. It has a key and stays active until it
is cleared. Setting it again with the same key updates it; unchanged repetitions
change nothing. Statuses have no trash button and never expire automatically.

A **message** describes a single event. It stays visible until its retention
expires or it is deleted with the trash button. `Sequence` is its unique
technical key. `Identifier` is an optional label that may be shared by several
messages. Messages without an identifier can only be deleted by their sequence.

Clicking an entry opens its target via `openObject()`. **Without a target**
(«None», Symcon object ID 1), a click does nothing.

Texts are shown as plain text, not as HTML. Keys, level IDs and identifiers are
compared case-insensitively.

## 4. Configuration

| Setting | Meaning |
|---|---|
| Levels | `ID`, integer `Priority` from 1 (1 = highest), `Color`, «Notify» |
| Grouping | statuses first, messages first or mixed |
| Status sorting | priority, newest or oldest first |
| Message sorting | newest, oldest or priority first |
| Date and time | shown or hidden; PHP date format, default `d.m. H:i` |
| Text when no items are active | free text |
| Summary variables | optional, see [Output variables](#8-output-variables); off by default |
| Default message retention | days, default 30; 0 = unlimited |
| History retention | days, default 365; 0 = unlimited |
| Maximum history entries | default 1000; 0 = unlimited |

The default levels are named in the IP-Symcon language when the instance is
created (e.g. Alarm, Warning, Information). The IDs can be changed freely;
scripts use the configured ID. Entries whose level no longer exists stay
visible, grey and with the lowest priority.

Messages that are still visible are never removed by history cleanup, so the
maximum number of entries is a soft limit.

## 5. Rules

All three rule lists have an «Active» column. Deactivated rules are ignored and
their statuses are cleared. Invalid rules (unknown level, empty text, missing
objects) are skipped and listed in the message log; the instance then shows
status 202. The remaining rules keep running.

### Placeholders

| Placeholder | Content |
|---|---|
| `{name}` | name of the variable or instance |
| `{parent}` | name of the parent object, e.g. the device |
| `{value}` | formatted value of the variable, with profile |
| `{age}` | time since the last update (update monitoring) |
| `{status}` | status code of the instance (instance monitoring) |
| `{statustext}` | plain text of the status code in the IP-Symcon language (instance monitoring) |

### Condition rules

The condition is defined with the Symcon condition editor. It is evaluated on
every update of an involved variable and at least once a minute (for date and
time conditions).

| Column | Meaning |
|---|---|
| Mode «Status» | active while the condition is met |
| Mode «Message» | exactly one message when the condition becomes met |
| Delay | seconds the condition must be met without interruption |
| Placeholder variable | source of `{name}`, `{parent}` and `{value}`; without a selection, the first variable of the condition |
| Target | click target in the tile; without a selection, no navigation |

If only the text of a status changes, e.g. through `{value}`, it is updated
silently without a history entry. The placeholder variable does not have to be
part of the condition; its updates keep `{value}` current. The start time of a
running delay is stored and survives a restart.

Messages created by a rule get the rule key as identifier and can be deleted
together with `NBOARD_DeleteMessagesByIdentifier()`.

Examples:

| Condition | Text | Mode | Delay |
|---|---|---|---|
| Fridge temperature > 8 | `{parent} too warm: {value}` | Status | 600 |
| Phase A voltage < 198 V | `Voltage phase A: {value}` | Status | 10 |
| Bathroom window = open AND rain = yes | `Bathroom: window open, it is raining` | Status | 0 |
| Doorbell = true | `Someone rang the doorbell` | Message | 0 |

Sequences that need memory, e.g. «the washing machine was running and is now
finished», cannot be expressed with a simple condition. Use the PHP API for
those.

### Update monitoring

A status appears when a variable has not been updated for longer than the
maximum age (minutes) and disappears with the next update. It is based on the
last update time stored by IP-Symcon, so the status and `{age}` survive a
restart. `{age}` keeps counting in the tile without filling the history.

### Instance monitoring

A status appears while an instance reports an error status (code 200 or
higher). It is evaluated on every status change of the instance and at least
once a minute. **Inactive instances (code 104) are not treated as errors** and
are not shown. A delay suppresses short interruptions, e.g. during a reconnect.

`{statustext}` is taken from the configuration form of the monitored instance
and translated with the translations shipped in that form. Without a
translation, the original text is shown; without any text, the code. The form
is only read while an error is active and the text contains `{statustext}`, and
is then cached per instance and code.

### Keys of rule statuses

Rule statuses use reserved keys: `condition:…`, `stale:<VariableID>` and
`instance:<InstanceID>`. Scripts can neither set nor clear such keys. For
condition rules, the key is derived from the condition and the mode: text,
level, target and delay can be changed without the status being recreated; a
changed condition counts as a new rule.

## 6. Notification

NoticeBoard does not send anything itself, but it can call a script of your
own that sends the notification, e.g. via push, Telegram or e-mail. The script
is selected under «Notification».

It is called for:

| `NBOARD_EVENT` | Event |
|---|---|
| `set` | new status |
| `changed` | logged status change (level, target, or text via the API) |
| `cleared` | cleared status |
| `message` | new message |

Silent text updates of rule statuses (`{value}`, `{age}`), unchanged
repetitions and deleting messages are not reported. Active statuses survive a
restart and are not reported again afterwards.

The only filter is the «Notify» column of the levels (default: on). Anything
else can be decided in the script.

The script runs asynchronously, once per event, and only after NoticeBoard has
saved its data. Simultaneous events are therefore not lost, NoticeBoard never
waits for the script, and the script may call `NBOARD_` functions itself. The
order of simultaneous calls is not guaranteed.

| Parameter | Content |
|---|---|
| `$_IPS['NBOARD_INSTANCE']` | ID of the NoticeBoard instance |
| `$_IPS['NBOARD_EVENT']` | `set`, `changed`, `cleared` or `message` |
| `$_IPS['NBOARD_TYPE']` | `status` or `message` |
| `$_IPS['NBOARD_SOURCE']` | `script`, `condition`, `stale` or `instance` |
| `$_IPS['NBOARD_LEVEL']` | level ID |
| `$_IPS['NBOARD_PRIORITY']` | priority of the level; 0 if the level no longer exists |
| `$_IPS['NBOARD_MESSAGE']` | text with placeholders already filled in |
| `$_IPS['NBOARD_KEY']` | key of the status; empty for messages |
| `$_IPS['NBOARD_IDENTIFIER']` | identifier of the message; empty for statuses |
| `$_IPS['NBOARD_SEQUENCE']` | sequence of the history entry; for messages usable with `NBOARD_DeleteMessage()` |
| `$_IPS['NBOARD_TARGET']` | target ID; 1 = no target |
| `$_IPS['NBOARD_ITEM']` | complete history entry as JSON |

Example using the notification of the tile visualization:

```php
<?php
if ($_IPS['SENDER'] !== 'RunScript' || !isset($_IPS['NBOARD_EVENT'])) {
    return;
}
if ($_IPS['NBOARD_EVENT'] === 'cleared') {
    return; // do not report cleared statuses
}

$VisuID = 12345; // ID of the tile visualization
$Type = (int) $_IPS['NBOARD_PRIORITY'] === 1 ? 'Alert' : 'Info';
VISU_PostNotification($VisuID, $_IPS['NBOARD_LEVEL'], $_IPS['NBOARD_MESSAGE'], $Type, (int) $_IPS['NBOARD_TARGET']);
```

## 7. PHP API

All arguments must be passed in IP-Symcon.

| Call | Return value and behavior |
|---|---|
| `NBOARD_SetStatusItem($InstanceID, $Key, $LevelID, $Message, $TargetID)` | `bool`: set or change a status |
| `NBOARD_ClearStatusItem($InstanceID, $Key)` | `bool`: status cleared; `false` if it does not exist |
| `NBOARD_AddMessage($InstanceID, $LevelID, $Message, $TargetID, $RetentionDays, $Identifier)` | `int`: new sequence; 0 for invalid data |
| `NBOARD_DeleteMessage($InstanceID, $Sequence)` | `bool`: message found and hidden |
| `NBOARD_DeleteMessagesByIdentifier($InstanceID, $Identifier)` | `int`: number of newly hidden messages; an empty identifier deletes nothing |
| `NBOARD_GetCurrentItems($InstanceID)` | JSON: current, sorted entries |
| `NBOARD_GetHistory($InstanceID)` | JSON: history, oldest first |
| `NBOARD_GetCount($InstanceID, $LevelID)` | `int`: number of active entries; `''` = all levels |
| `NBOARD_GetHighestLevel($InstanceID)` | `string`: highest active level; `''` = none |
| `NBOARD_ClearHistory($InstanceID)` | deletes the entire history, including visible messages; active statuses remain |

- `$TargetID`: `1` = no target (Symcon default; `0` is treated as no target as
  well), otherwise an existing object ID.
- `$RetentionDays`: `0` = instance default, `-1` = unlimited, otherwise days.
- Invalid data (empty text, unknown level, missing target) returns `false` or 0
  and a warning.
- Concurrent calls are processed one after another per instance. After waiting
  five seconds, an exception is thrown.

## 8. Output variables

| Variable | Content |
|---|---|
| `CurrentItems` | JSON: current, sorted entries |
| `History` | JSON: messages and status events |
| `ActiveCount` | number of active entries (only with summary variables) |
| `HighestLevel` | highest active level (only with summary variables) |

The summary variables are off by default, because variables count towards some
licenses. `NBOARD_GetCount()` and `NBOARD_GetHighestLevel()` provide the same
values without additional variables.

All variables are read-only and are only written when their content changes,
`History` always before `CurrentItems`. A script reacting to `CurrentItems`
therefore already finds the matching history data.

All entries contain `Source` (`script`, `condition`, `stale`, `instance`). Status
events in the history contain `Event` (`set`, `changed`, `cleared`) and
`EventTime`; changes additionally contain `PreviousLevel`, `PreviousMessage` and
`PreviousTargetID`, cleared statuses `Resolved` and `Duration` in seconds.
Deleted messages keep their data with `Deleted = true` and `DeletedAt`.

## 9. Operation

- Data is only written when it changes; the tile is only updated when its
  content changes.
- The maintenance timer runs at most once a minute, and earlier when a delay or
  an expiry is due.
- After a system start, rules are evaluated once IP-Symcon is ready.
- Statuses, history and rule states are stored in instance attributes and are
  part of the Symcon backup. Deleting the instance deletes its data.
- Times are formatted in the time zone of the Symcon server.

## 10. Support

This module is free of charge. Donations to support the author are gratefully
accepted: PayPal to ueli.schweizer@cassanis.fr

## 11. Changelog

**2.0 Build 1:** First public beta version.
