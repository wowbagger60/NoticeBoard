<?php
// Standalone behavioural tests, not a substitute for the Symcon kernel.
declare(strict_types=1);

const VM_UPDATE = 10603;
const IM_CHANGESTATUS = 10505;
const IPS_KERNELSTARTED = 10001;
const KR_READY = 10103;
const KL_WARNING = 10206;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_STRING = 3;

$GLOBALS['values'] = [];
$GLOBALS['idents'] = [];
$GLOBALS['locks'] = [];
$GLOBALS['failLock'] = false;
$GLOBALS['runlevel'] = KR_READY;
$GLOBALS['conditions'] = [];
// Simulated foreign objects: variables 500-599, instances 600-699, other objects 12345, 23456. ID 1 = no object.
$GLOBALS['variables'] = [
    501 => ['VariableUpdated' => time(), 'Name' => 'Temperatur', 'Parent' => 600, 'Formatted' => '9,3 °C'],
    502 => ['VariableUpdated' => time(), 'Name' => 'Fenster', 'Parent' => 601, 'Formatted' => 'offen']
];
$GLOBALS['instances'] = [600 => ['Name' => 'Kühlschrank', 'Status' => 102], 601 => ['Name' => 'Bad', 'Status' => 102]];

function IPS_ObjectExists(int $id): bool {
    return in_array($id, [12345, 23456], true) || isset($GLOBALS['variables'][$id]) || isset($GLOBALS['instances'][$id]);
}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['variables'][$id]); }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetVariable(int $id): array { return ['VariableUpdated' => $GLOBALS['variables'][$id]['VariableUpdated']]; }
function IPS_GetInstance(int $id): array { return ['InstanceStatus' => $GLOBALS['instances'][$id]['Status']]; }
function IPS_GetName(int $id): string { return $GLOBALS['variables'][$id]['Name'] ?? $GLOBALS['instances'][$id]['Name'] ?? 'Object'; }
function IPS_GetParent(int $id): int { return $GLOBALS['variables'][$id]['Parent'] ?? 0; }
function GetValueFormatted(int $id): string { return $GLOBALS['variables'][$id]['Formatted']; }
function IPS_IsConditionPassing(string $condition): bool { return $GLOBALS['conditions'][$condition] ?? false; }
function IPS_GetKernelRunlevel(): int { return $GLOBALS['runlevel']; }
$GLOBALS['scripts'] = [700];
$GLOBALS['scriptCalls'] = [];
function IPS_ScriptExists(int $id): bool { return in_array($id, $GLOBALS['scripts'], true); }
function IPS_RunScriptEx(int $id, array $parameters): bool {
    check($GLOBALS['locks'] === [], 'notification script runs outside the data lock');
    $GLOBALS['scriptCalls'][] = $parameters;
    return true;
}
$GLOBALS['formCalls'] = 0;
$GLOBALS['translate'] = [];
function IPS_GetConfigurationForm(int $id): string {
    $GLOBALS['formCalls']++;
    return json_encode([
        'elements' => [], 'actions' => [],
        'status' => [['code' => 102, 'icon' => 'active', 'caption' => 'Open'], ['code' => 201, 'icon' => 'error', 'caption' => 'Connection lost']],
        'translations' => ['de' => ['Connection lost' => 'Verbindung verloren']]
    ]);
}
function GetValue(int $id): mixed { return $GLOBALS['values'][$id] ?? null; }
function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false {
    if (!isset($GLOBALS['idents'][$parent][$ident])) { trigger_error('ident not found', E_USER_WARNING); return false; }
    return $GLOBALS['idents'][$parent][$ident];
}
function IPS_SemaphoreEnter(string $name, int $timeout): bool {
    if ($GLOBALS['failLock'] || isset($GLOBALS['locks'][$name])) { return false; }
    $GLOBALS['locks'][$name] = true;
    return true;
}
function IPS_SemaphoreLeave(string $name): void { unset($GLOBALS['locks'][$name]); }

class IPSModuleStrict {
    public int $InstanceID;
    public array $properties = [], $attributes = [], $references = [], $messages = [], $writes = [], $buffers = [], $log = [];
    public int $visualization = 0, $status = 0, $timer = 0, $renders = 0;
    public string $renderData = '';
    public function __construct(int $id) { $this->InstanceID = $id; }
    public function Create(): void {}
    public function ApplyChanges(): void {}
    protected function RegisterPropertyString(string $id, string $value): void { $this->properties[$id] ??= $value; }
    protected function RegisterPropertyInteger(string $id, int $value): void { $this->properties[$id] ??= $value; }
    protected function RegisterPropertyBoolean(string $id, bool $value): void { $this->properties[$id] ??= $value; }
    protected function RegisterAttributeString(string $id, string $value): void { $this->attributes[$id] ??= $value; }
    protected function RegisterAttributeInteger(string $id, int $value): void { $this->attributes[$id] ??= $value; }
    protected function RegisterVariableString(string $id, string $name, string $profile, int $pos): void { $this->MaintainVariable($id, $name, VARIABLETYPE_STRING, $profile, $pos, true); }
    protected function MaintainVariable(string $id, string $name, int $type, string $profile, int $pos, bool $keep): void {
        if (!$keep) { unset($GLOBALS['idents'][$this->InstanceID][$id]); return; }
        $GLOBALS['idents'][$this->InstanceID][$id] ??= $this->InstanceID * 100 + count($GLOBALS['idents'][$this->InstanceID] ?? []);
        $GLOBALS['values'][$GLOBALS['idents'][$this->InstanceID][$id]] ??= $type === VARIABLETYPE_INTEGER ? 0 : '';
    }
    protected function ReadPropertyString(string $id): string { return $this->properties[$id]; }
    protected function ReadPropertyInteger(string $id): int { return $this->properties[$id]; }
    protected function ReadPropertyBoolean(string $id): bool { return $this->properties[$id]; }
    protected function ReadAttributeString(string $id): string { return $this->attributes[$id]; }
    protected function ReadAttributeInteger(string $id): int { return $this->attributes[$id]; }
    protected function WriteAttributeString(string $id, string $value): void { $this->attributes[$id] = $value; }
    protected function WriteAttributeInteger(string $id, int $value): void { $this->attributes[$id] = $value; }
    protected function RegisterTimer(string $id, int $time, string $code): void { $this->timer = $time; }
    protected function SetTimerInterval(string $id, int $time): void { $this->timer = $time; }
    protected function SetStatus(int $value): void { $this->status = $value; }
    protected function SetVisualizationType(int $value): void { $this->visualization = $value; }
    protected function RegisterReference(int $id): void { $this->references[$id] = true; }
    protected function UnregisterReference(int $id): void { unset($this->references[$id]); }
    protected function GetReferenceList(): array { return array_keys($this->references); }
    protected function RegisterMessage(int $sender, int $message): void { $this->messages[$sender][$message] = true; }
    protected function UnregisterMessage(int $sender, int $message): void { unset($this->messages[$sender][$message]); }
    protected function GetMessageList(): array { return array_map('array_keys', array_filter($this->messages)); }
    protected function GetBuffer(string $id): string { return $this->buffers[$id] ?? ''; }
    protected function SetBuffer(string $id, string $value): void { $this->buffers[$id] = $value; }
    protected function LogMessage(string $message, int $type): void { $this->log[] = $message; }
    protected function SetValue(string $id, mixed $value): void {
        $GLOBALS['values'][$GLOBALS['idents'][$this->InstanceID][$id]] = $value;
        $this->writes[] = $id;
        if ($id === 'CurrentItems') {
            check(GetValue($GLOBALS['idents'][$this->InstanceID]['History']) === $this->attributes['HistoryData'], 'history is current before CurrentItems publication');
        }
    }
    protected function UpdateVisualizationValue(string $value): void { $this->renderData = $value; $this->renders++; }
    protected function Translate(string $value): string { return $GLOBALS['translate'][$value] ?? $value; }
}
require __DIR__ . '/../NoticeBoard/module.php';

$checks = 0;
function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    $GLOBALS['checks']++;
}
function instance(int $id, array $properties = []): NoticeBoard {
    $m = new NoticeBoard($id); $m->Create();
    foreach ($properties as $key => $value) { $m->properties[$key] = is_array($value) ? json_encode($value) : $value; }
    $m->ApplyChanges(); return $m;
}
function currentItems(NoticeBoard $m): array { return json_decode($m->GetCurrentItems(), true, 512, JSON_THROW_ON_ERROR); }
function history(NoticeBoard $m): array { return json_decode($m->GetHistory(), true, 512, JSON_THROW_ON_ERROR); }
function statuses(NoticeBoard $m): array { return json_decode($m->attributes['Statuses'], true); }
function expectWarning(callable $call, mixed $expected): void {
    $warnings = 0;
    set_error_handler(function () use (&$warnings) { $warnings++; return true; });
    try { check($call() === $expected && $warnings === 1, 'invalid input rejected with one warning'); }
    finally { restore_error_handler(); }
}

// ---------------------------------------------------------------- script API
$m = instance(10);
check($m->status === 102 && currentItems($m) === [] && history($m) === [], 'empty instance initializes');
check(count($m->references) === 2 && $m->visualization === 1, 'own references and legacy HTML fallback');
check(isset($m->messages[0][IPS_KERNELSTARTED]), 'kernel start registered');
define('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN', 2);
$m->ApplyChanges();
check($m->visualization === 2, 'existing instance gains fullscreen on ApplyChanges');
check($m->SetStatusItem('heating', 'aLaRm', 'Failure', 12345), 'status accepted');
$initial = currentItems($m)[0];
check($initial['Source'] === 'script', 'script source recorded');
$before = $m->GetCurrentItems(); $count = count(history($m)); $writes = count($m->writes); $renders = $m->renders;
$m->SetStatusItem('HEATING', 'Alarm', 'Failure', 12345);
check($before === $m->GetCurrentItems() && $count === count(history($m)) && $writes === count($m->writes) && $renders === $m->renders, 'identical status causes no output/history/render changes');
$m->SetStatusItem('heating', 'Warning', 'Changed', 23456);
$changed = currentItems($m)[0]; $event = array_slice(history($m), -1)[0];
check($changed['Sequence'] === $initial['Sequence'] && $changed['Created'] === $initial['Created'], 'status identity and creation retained');
check($event['Event'] === 'changed' && $event['PreviousMessage'] === 'Failure' && $event['PreviousTargetID'] === 12345, 'change history retains previous data');
expectWarning(fn() => $m->SetStatusItem('stale:501', 'Alarm', 'reserved', 0), false);
check(!$m->ClearStatusItem('condition:abc'), 'reserved keys cannot be cleared by scripts');
$a = $m->AddMessage('Information', 'Same text', 0, 0, ' Tag ');
$b = $m->AddMessage('Information', 'Same text', 0, 0, 'tag');
$c = $m->AddMessage('Information', 'No identifier', 1, 0, '');
check(array_slice(history($m), -1)[0]['TargetID'] === 1, 'target 1 means no object');
check(array_slice(history($m), -2)[0]['TargetID'] === 1, 'legacy target 0 normalized to 1');
check($b > $a && currentItems($m)[1]['Sequence'] === $c, 'same-second ordering and unique message identities');
$m->writes = [];
check($m->DeleteMessage($c), 'message hidden');
check($m->writes === ['History', 'CurrentItems'], 'history output precedes current output');
check($m->DeleteMessage($c) && !$m->DeleteMessage(999999), 'delete is idempotent, absent message rejected');
check($m->DeleteMessagesByIdentifier('TAG') === 2 && $m->DeleteMessagesByIdentifier('tag') === 0, 'delete by identifier, case-insensitive, counts newly hidden');
check($m->DeleteMessagesByIdentifier('') === 0, 'empty identifier deletes nothing');
check(count(currentItems($m)) === 1, 'only status remains');
$restored = new NoticeBoard(10); $restored->properties = $m->properties; $restored->attributes = $m->attributes; $restored->Create(); $restored->ApplyChanges();
check(currentItems($restored) === currentItems($m) && history($restored) === history($m), 'persistent attributes survive lifecycle reconstruction');
check($restored->AddMessage('Alarm', 'Next', 0, 0, '') > $c, 'sequence continues after restart');
check($m->ClearStatusItem('HEATING') && !$m->ClearStatusItem('heating'), 'clear status case insensitive');
$event = array_slice(history($m), -1)[0];
check($event['Event'] === 'cleared' && isset($event['Resolved'], $event['Duration']), 'clear status history');
expectWarning(fn() => $m->AddMessage('Unknown', 'text', 0, 0, ''), 0);
expectWarning(fn() => $m->AddMessage('Alarm', '', 0, 0, ''), 0);
expectWarning(fn() => $m->AddMessage('Alarm', 'text', 999999, 0, ''), 0);
expectWarning(fn() => $m->AddMessage('Alarm', 'text', 0, -2, ''), 0);
expectWarning(fn() => $m->SetStatusItem('', 'Alarm', 'text', 0), false);
check($GLOBALS['locks'] === [], 'locks released on invalid input');
try { $m->AddMessage('Alarm', "\xFF", 0, 0, ''); check(false, 'invalid UTF8 must throw'); }
catch (JsonException $e) { check($GLOBALS['locks'] === [], 'lock released after JSON exception'); }
$GLOBALS['failLock'] = true;
try { $m->GetHistory(); check(false, 'lock timeout must throw'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'busy'), 'timeout reported without mutation'); }
$GLOBALS['failLock'] = false;

// ---------------------------------------------------------------- retention and timer
$m = instance(20, ['HistoryRetentionDays' => 1, 'MaximumHistoryEntries' => 1]);
$m->SetStatusItem('active', 'Alarm', 'Active', 0);
$visible = $m->AddMessage('Information', 'Unlimited', 0, -1, '');
$m->AddMessage('Information', 'Expired', 0, 1, '');
$h = history($m);
foreach ($h as &$e) { $e['Created'] = time() - 3 * 86400; $e['EventTime'] = time() - 3 * 86400; } unset($e);
$m->attributes['HistoryData'] = json_encode($h);
$m->RequestAction('Maintenance', 0);
check(count(currentItems($m)) === 2 && count(history($m)) === 1 && history($m)[0]['Sequence'] === $visible, 'active status and unlimited message protected, old hidden history purged');
check($m->timer === 60000, 'idle timer at 60 s');
$m->ClearHistory();
check(count(currentItems($m)) === 1 && history($m) === [], 'clear history keeps active status');
$attributeBefore = $m->attributes;
$m->RequestAction('Maintenance', 0);
check($m->attributes === $attributeBefore, 'maintenance without changes writes no attributes');

$m = instance(30, ['HistoryRetentionDays' => 0, 'MaximumHistoryEntries' => 0]);
$m->AddMessage('Information', 'Default', 0, 0, '');
$h = history($m); $h[0]['Created'] = time() - 2 * 86400; $m->attributes['HistoryData'] = json_encode($h);
$m->properties['MessageRetentionDays'] = 1; $m->RequestAction('Maintenance', 0);
check(currentItems($m) === [] && !history($m)[0]['Deleted'], 'expiry not treated as deletion');
$m->properties['MessageRetentionDays'] = 0; $m->RequestAction('Maintenance', 0);
check(count(currentItems($m)) === 1, 'global default applied dynamically');
$m->SetStatusItem('s', 'Alarm', 'Status', 0);
$m->properties['Grouping'] = 1; check(currentItems($m)[0]['Type'] === 'message', 'messages first');
$m->properties['Grouping'] = 2; check(currentItems($m)[0]['Type'] === 'status', 'mixed priority ordering');
check($m->GetCount('') === 2 && $m->GetCount('alarm') === 1 && $m->GetCount('Warning') === 0, 'GetCount per level');
check($m->GetHighestLevel() === 'Alarm', 'highest level');

// ---------------------------------------------------------------- summary variables
$m = instance(35, ['SummaryVariables' => true]);
$m->AddMessage('Warning', 'W', 0, 0, '');
check(GetValue($GLOBALS['idents'][35]['ActiveCount']) === 1 && GetValue($GLOBALS['idents'][35]['HighestLevel']) === 'Warning', 'summary variables maintained');
check(count($m->references) === 4, 'summary variables referenced');
$m->properties['SummaryVariables'] = false; $m->ApplyChanges();
check(!isset($GLOBALS['idents'][35]['ActiveCount']) && count($m->references) === 2, 'summary variables removed when disabled');

// ---------------------------------------------------------------- condition rules
$cond = json_encode([['rules' => ['variable' => [['variableID' => 501, 'comparison' => 3, 'value' => 8]]]]]);
$rules = [['Active' => true, 'Condition' => $cond, 'Level' => 'Alarm', 'Text' => '{parent}: {value}', 'Mode' => 0, 'Delay' => 600, 'TargetID' => 0]];
$m = instance(40, ['ConditionRules' => $rules]);
check($m->status === 102 && isset($m->messages[501][VM_UPDATE]) && isset($m->references[501]), 'condition variable registered and referenced');
check(currentItems($m) === [], 'condition false: no status');
$GLOBALS['conditions'][$cond] = true;
$m->MessageSink(time(), 501, VM_UPDATE, []);
check(currentItems($m) === [] && $m->timer <= 600000, 'delay pending, timer armed for due time');
$state = json_decode($m->attributes['RuleState'], true); $key = array_key_first($state);
check(str_starts_with($key, 'condition:') && $state[$key]['Since'] > 0, 'pending since persisted');
$state[$key]['Since'] = time() - 601; $m->attributes['RuleState'] = json_encode($state);
$m->RequestAction('Maintenance', 0);
$item = currentItems($m)[0] ?? [];
check(($item['Message'] ?? '') === 'Kühlschrank: 9,3 °C' && $item['Source'] === 'condition' && $item['TargetID'] === 1, 'status after delay with placeholders, no target');
$count = count(history($m));
$GLOBALS['variables'][501]['Formatted'] = '9,8 °C';
$m->MessageSink(time(), 501, VM_UPDATE, []);
check(currentItems($m)[0]['Message'] === 'Kühlschrank: 9,8 °C' && count(history($m)) === $count, 'value text updated silently');
$GLOBALS['conditions'][$cond] = false;
$m->MessageSink(time(), 501, VM_UPDATE, []);
check(currentItems($m) === [] && array_slice(history($m), -1)[0]['Event'] === 'cleared', 'condition false clears status');
check(json_decode($m->attributes['RuleState'], true) === [], 'rule state removed');
// Restart while active: status kept even though Since is lost.
$GLOBALS['conditions'][$cond] = true;
$state = [$key => ['Since' => time() - 700, 'Fired' => false]]; $m->attributes['RuleState'] = json_encode($state);
$m->RequestAction('Maintenance', 0);
check(count(currentItems($m)) === 1, 'reactivated');
$m->attributes['RuleState'] = '{}'; $m->RequestAction('Maintenance', 0);
check(count(currentItems($m)) === 1, 'active status survives lost pending state');
// Removing the rule resolves its status.
$m->properties['ConditionRules'] = '[]'; $m->ApplyChanges();
check(currentItems($m) === [] && empty($m->messages[501]), 'removed rule clears status and unregisters');

// Placeholder variable overrides the first condition variable and is watched.
$withPlaceholder = $rules; $withPlaceholder[0]['Delay'] = 0; $withPlaceholder[0]['Text'] = '{name}: {value}'; $withPlaceholder[0]['PlaceholderVariableID'] = 502;
$p = instance(45, ['ConditionRules' => $withPlaceholder]);
check((currentItems($p)[0]['Message'] ?? '') === 'Fenster: offen' && isset($p->messages[502][VM_UPDATE]), 'placeholder variable used and registered');
$withPlaceholder[0]['PlaceholderVariableID'] = 1;
$p = instance(46, ['ConditionRules' => $withPlaceholder]);
check((currentItems($p)[0]['Message'] ?? '') === 'Temperatur: 9,8 °C', 'placeholder variable 1 falls back to first condition variable');
$withPlaceholder[0]['PlaceholderVariableID'] = 599;
$p = instance(47, ['ConditionRules' => $withPlaceholder]);
check($p->status === 202 && currentItems($p) === [], 'missing placeholder variable reported');

// Message mode: one message per rising edge, identifier is the rule key.
$rules[0]['Mode'] = 1; $rules[0]['Delay'] = 0;
$m = instance(50, ['ConditionRules' => $rules]);
$GLOBALS['conditions'][$cond] = true;
$m->MessageSink(time(), 501, VM_UPDATE, []);
$m->MessageSink(time(), 501, VM_UPDATE, []);
$items = currentItems($m);
check(count($items) === 1 && $items[0]['Type'] === 'message' && str_starts_with($items[0]['Identifier'], 'condition:'), 'message fired once with rule key identifier');
$GLOBALS['conditions'][$cond] = false; $m->MessageSink(time(), 501, VM_UPDATE, []);
$GLOBALS['conditions'][$cond] = true; $m->MessageSink(time(), 501, VM_UPDATE, []);
check(count(currentItems($m)) === 2, 'second rising edge creates second message');
check($m->DeleteMessagesByIdentifier($items[0]['Identifier']) === 2, 'rule messages deletable by identifier');

// Invalid rules.
$bad = [['Active' => true, 'Condition' => '', 'Level' => 'Alarm', 'Text' => 'x', 'Mode' => 0, 'Delay' => 0, 'TargetID' => 0],
        ['Active' => true, 'Condition' => $cond, 'Level' => 'Nope', 'Text' => 'x', 'Mode' => 0, 'Delay' => 0, 'TargetID' => 0],
        ['Active' => false, 'Condition' => '', 'Level' => '', 'Text' => '', 'Mode' => 0, 'Delay' => 0, 'TargetID' => 0]];
$m = instance(60, ['ConditionRules' => $bad]);
check($m->status === 202 && count($m->log) === 2, 'invalid rules reported, inactive rows ignored');

// ---------------------------------------------------------------- update monitoring
$GLOBALS['variables'][502]['VariableUpdated'] = time() - 2 * 3600 - 5 * 60;
$m = instance(70, ['StaleRules' => [['Active' => true, 'VariableID' => 502, 'MaxAge' => 60, 'Level' => 'Warning', 'Text' => '{name}: {age}', 'TargetID' => 0]]]);
$item = currentItems($m)[0] ?? [];
check(($item['Message'] ?? '') === 'Fenster: 2 h 5 min' && $item['Key'] === 'stale:502', 'stale status with age');
check(str_contains(json_decode($m->attributes['Statuses'], true)['stale:502']['Message'], '{age}'), 'age stored as placeholder, resolved at display time');
$GLOBALS['variables'][502]['VariableUpdated'] = time();
$m->MessageSink(time(), 502, VM_UPDATE, []);
$cleared = array_slice(history($m), -1)[0];
check(currentItems($m) === [] && $cleared['Event'] === 'cleared' && !str_contains($cleared['Message'], '{age}'), 'update clears stale status, history text resolved');
check($m->timer <= 3600000 && $m->timer > 0, 'timer armed');

// ---------------------------------------------------------------- instance monitoring
$m = instance(80, ['InstanceRules' => [['Active' => true, 'InstanceID' => 600, 'Level' => 'Alarm', 'Text' => '{name} ({status})', 'Delay' => 0, 'TargetID' => 600]]]);
check(isset($m->messages[600][IM_CHANGESTATUS]) && currentItems($m) === [], 'instance registered, ok');
$GLOBALS['instances'][600]['Status'] = 201;
$m->MessageSink(time(), 600, IM_CHANGESTATUS, []);
check((currentItems($m)[0]['Message'] ?? '') === 'Kühlschrank (201)' && currentItems($m)[0]['TargetID'] === 600, 'instance error shown');
$GLOBALS['instances'][600]['Status'] = 102;
$m->MessageSink(time(), 600, IM_CHANGESTATUS, []);
check(currentItems($m) === [], 'instance recovery clears status');
check($GLOBALS['formCalls'] === 0, 'foreign form not read when {statustext} is unused');
// {statustext}: caption from the foreign form, translated with its own translations.
$m = instance(85, ['InstanceRules' => [['Active' => true, 'InstanceID' => 600, 'Level' => 'Alarm', 'Text' => '{name}: {statustext}', 'Delay' => 0, 'TargetID' => 1]]]);
$GLOBALS['instances'][600]['Status'] = 201;
$m->MessageSink(time(), 600, IM_CHANGESTATUS, []);
check((currentItems($m)[0]['Message'] ?? '') === 'Kühlschrank: Connection lost', 'untranslated caption without own language');
$m->MessageSink(time(), 600, IM_CHANGESTATUS, []);
check($GLOBALS['formCalls'] === 1, 'status text cached');
$GLOBALS['translate'] = ['__language' => 'de'];
$m = instance(86, ['InstanceRules' => [['Active' => true, 'InstanceID' => 600, 'Level' => 'Alarm', 'Text' => '{name}: {statustext}', 'Delay' => 0, 'TargetID' => 1]]]);
check((currentItems($m)[0]['Message'] ?? '') === 'Kühlschrank: Verbindung verloren', 'caption translated into own language');
$GLOBALS['instances'][600]['Status'] = 205;
$m->MessageSink(time(), 600, IM_CHANGESTATUS, []);
check((currentItems($m)[0]['Message'] ?? '') === 'Kühlschrank: 205', 'unknown code falls back to number');
$GLOBALS['translate'] = [];
$GLOBALS['instances'][600]['Status'] = 102;

// ---------------------------------------------------------------- notification script
$GLOBALS['instances'][600]['Status'] = 102;
$levels = [['ID' => 'Alarm', 'Priority' => 1, 'Color' => '#E53935', 'Notify' => true],
           ['ID' => 'Information', 'Priority' => 3, 'Color' => '#1E88E5', 'Notify' => false]];
$m = instance(95, ['Levels' => $levels, 'NotificationScript' => 700]);
check(isset($m->references[700]) && $GLOBALS['scriptCalls'] === [], 'script referenced, nothing reported yet');
$m->SetStatusItem('pump', 'Alarm', 'Pump failure', 1);
$m->SetStatusItem('pump', 'Alarm', 'Pump failure', 1);
$m->AddMessage('Alarm', 'Door opened', 1, 0, 'door');
$m->AddMessage('Information', 'Silent', 1, 0, '');
$m->ClearStatusItem('pump');
$events = array_map(fn($c) => $c['NBOARD_EVENT'] . ':' . $c['NBOARD_MESSAGE'], $GLOBALS['scriptCalls']);
check($events === ['set:Pump failure', 'message:Door opened', 'cleared:Pump failure'], 'one call per event, repeats and silent levels suppressed');
$call = $GLOBALS['scriptCalls'][1];
check($call['NBOARD_INSTANCE'] === 95 && $call['NBOARD_LEVEL'] === 'Alarm' && $call['NBOARD_PRIORITY'] === 1 && $call['NBOARD_IDENTIFIER'] === 'door' && $call['NBOARD_TARGET'] === 1 && json_decode($call['NBOARD_ITEM'], true)['Message'] === 'Door opened', 'parameters complete');
$GLOBALS['scriptCalls'] = [];
$m->DeleteMessagesByIdentifier('door');
check($GLOBALS['scriptCalls'] === [], 'deleting is not reported');
$m = instance(96, ['NotificationScript' => 799]);
check($m->status === 202, 'missing notification script reported');
$GLOBALS['scriptCalls'] = [];

// ---------------------------------------------------------------- startup
$GLOBALS['runlevel'] = 0;
$m = instance(90, ['InstanceRules' => [['Active' => true, 'InstanceID' => 600, 'Level' => 'Alarm', 'Text' => 'x', 'Delay' => 0, 'TargetID' => 0]]]);
check($m->status === 0 && $m->renders === 0, 'nothing evaluated before kernel ready');
$GLOBALS['runlevel'] = KR_READY;
$m->MessageSink(time(), 0, IPS_KERNELSTARTED, []);
check($m->status === 102 && $m->renders === 1, 'initialized on kernel start');

// ---------------------------------------------------------------- levels, form, tile
$m = instance(100);
$levels = [['ID'=>'Alarm','Priority'=>1,'Color'=>0xE53935], ['ID'=>'Info','Priority'=>1,'Color'=>'#1e88e5']];
$m->SetStatusItem('s', 'Warning', 'Status', 0);
$m->properties['Levels'] = json_encode($levels); $m->ApplyChanges();
check($m->status === 102, 'integer and hex colors with equal priority accepted');
check(currentItems($m)[0]['Color'] === '#808080', 'removed level falls back to grey');
$form = json_decode($m->GetConfigurationForm(), true);
$staleList = $form['elements'][3]['items'][0];
$levelColumn = array_values(array_filter($staleList['columns'], fn($c) => $c['name'] === 'Level'))[0];
check(count($levelColumn['edit']['options']) === 2 && $levelColumn['add'] === 'Info', 'level options injected, least urgent as default');
$m->properties['Levels'] = json_encode([['ID'=>'Alarm','Priority'=>1,'Color'=>0], ['ID'=>'alarm','Priority'=>2,'Color'=>0]]); $m->ApplyChanges();
check($m->status === 201 && $m->timer === 0, 'duplicate level rejected, timer disabled');
$m->properties['Levels'] = json_encode($levels); $m->ApplyChanges();
$payload = '</script><script>window.injected=true</script>&"';
$m->AddMessage('Alarm', $payload, 0, 0, '');
$html = $m->GetVisualizationTile();
preg_match('/let data = (.*);/', $html, $match);
$render = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
check(!str_contains($html, $payload) && $render['Items'][1]['Message'] === $payload, 'HTML embedding escapes closing script tags and preserves text');
$renders = $m->renders; $m->RequestAction('Refresh', 0);
check($m->renders === $renders + 1, 'refresh forces render');

$methods = array_filter((new ReflectionClass(NoticeBoard::class))->getMethods(ReflectionMethod::IS_PUBLIC), fn($method) => $method->getDeclaringClass()->getName() === NoticeBoard::class);
check(count($methods) === 16, 'six lifecycle/SDK methods and ten documented API methods are public');
check($GLOBALS['locks'] === [], 'all locks released');
echo 'PASS: ' . $checks . " behavioural assertions\n";
