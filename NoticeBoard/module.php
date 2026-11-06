<?php

declare(strict_types=1);

class NoticeBoard extends IPSModuleStrict
{
    private const TYPE_STATUS = 'status';
    private const TYPE_MESSAGE = 'message';

    private const EVENT_SET = 'set';
    private const EVENT_CHANGED = 'changed';
    private const EVENT_CLEARED = 'cleared';

    private const SOURCE_SCRIPT = 'script';
    private const SOURCE_CONDITION = 'condition';
    private const SOURCE_STALE = 'stale';
    private const SOURCE_INSTANCE = 'instance';

    private const MODE_STATUS = 0;
    private const MODE_MESSAGE = 1;

    private const STATUS_ACTIVE = 102;
    private const STATUS_INVALID_LEVELS = 201;
    private const STATUS_INVALID_RULES = 202;

    private const MAX_TIMER_SECONDS = 60;

    /** Symcon standard for "no object selected". */
    private const NO_OBJECT = 1;

    /** Working copies, loaded and committed within one locked operation. */
    private array $statuses = [];
    private array $history = [];
    private array $ruleState = [];
    private ?int $nextDue = null;
    private array $notifications = [];

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Levels', json_encode([
            ['ID' => $this->Translate('Alarm'), 'Priority' => 1, 'Color' => '#E53935'],
            ['ID' => $this->Translate('Warning'), 'Priority' => 2, 'Color' => '#FB8C00'],
            ['ID' => $this->Translate('Information'), 'Priority' => 3, 'Color' => '#1E88E5']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->RegisterPropertyString('ConditionRules', '[]');
        $this->RegisterPropertyString('StaleRules', '[]');
        $this->RegisterPropertyString('InstanceRules', '[]');
        $this->RegisterPropertyInteger('Grouping', 0);
        $this->RegisterPropertyInteger('StatusSorting', 0);
        $this->RegisterPropertyInteger('MessageSorting', 0);
        $this->RegisterPropertyBoolean('ShowTimestamp', true);
        $this->RegisterPropertyString('DateFormat', 'd.m. H:i');
        $this->RegisterPropertyString('EmptyText', $this->Translate('No active notices'));
        $this->RegisterPropertyInteger('MessageRetentionDays', 30);
        $this->RegisterPropertyInteger('HistoryRetentionDays', 365);
        $this->RegisterPropertyInteger('MaximumHistoryEntries', 1000);
        $this->RegisterPropertyBoolean('SummaryVariables', false);
        $this->RegisterPropertyInteger('NotificationScript', self::NO_OBJECT);

        $this->RegisterAttributeString('Statuses', '{}');
        $this->RegisterAttributeString('HistoryData', '[]');
        $this->RegisterAttributeString('RuleState', '{}');
        $this->RegisterAttributeInteger('NextSequence', 1);

        $this->RegisterVariableString('CurrentItems', $this->Translate('Current Items'), '', 10);
        $this->RegisterVariableString('History', $this->Translate('History'), '', 20);

        $this->RegisterTimer(
            'Maintenance',
            self::MAX_TIMER_SECONDS * 1000,
            'IPS_RequestAction($_IPS["TARGET"], "Maintenance", 0);'
        );

        $this->ConfigureVisualization();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->ConfigureVisualization();

        $summary = $this->ReadPropertyBoolean('SummaryVariables');
        $this->MaintainVariable('ActiveCount', $this->Translate('Active items'), VARIABLETYPE_INTEGER, '', 30, $summary);
        $this->MaintainVariable('HighestLevel', $this->Translate('Highest level'), VARIABLETYPE_STRING, '', 40, $summary);

        $this->RegisterSourcesAndReferences();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            // Initialize() runs on IPS_KERNELSTARTED.
            return;
        }

        $this->Initialize();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->Initialize();
                break;

            case VM_UPDATE:
            case IM_CHANGESTATUS:
                if ($this->GetLevels() !== null) {
                    $this->Process();
                }
                break;
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DeleteMessage':
                $this->DeleteMessage((int) $Value);
                break;

            case 'Refresh':
                $this->Process(null, true, true);
                break;

            case 'Maintenance':
                $this->Process(null, true, false, true);
                break;

            default:
                throw new InvalidArgumentException('Invalid Ident');
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);

        $levels = $this->GetLevels() ?? [];
        $options = array_map(
            fn(array $level): array => ['caption' => $level['ID'], 'value' => $level['ID']],
            $levels
        );
        $defaultLevel = '';
        $lowestPriority = 0;
        foreach ($levels as $level) {
            if ($level['Priority'] >= $lowestPriority) {
                $lowestPriority = $level['Priority'];
                $defaultLevel = $level['ID'];
            }
        }

        $defaultTexts = [
            'ConditionRules' => '{name}: {value}',
            'StaleRules'     => $this->Translate('{name}: no update for {age}'),
            'InstanceRules'  => '{name}: {statustext}'
        ];

        $form['elements'] = $this->PrepareFormElements($form['elements'], $options, $defaultLevel, $defaultTexts);

        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetVisualizationTile(): string
    {
        $data = $this->ReadData(fn(int $now): array => $this->BuildRenderData($this->BuildCurrentItems($now)));
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $html = (string) file_get_contents(__DIR__ . '/assets/index.html');
        return str_replace('/*DATA*/', 'let data = ' . $json . ';', $html);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public function SetStatusItem(string $Key, string $LevelID, string $Message, int $TargetID): bool
    {
        $key = trim($Key);
        $message = trim($Message);
        $level = $this->FindLevel($LevelID);
        $TargetID = $this->NormalizeObjectID($TargetID);

        if (
            $key === '' ||
            $this->IsReservedKey($key) ||
            $message === '' ||
            $level === null ||
            !$this->IsValidTarget($TargetID)
        ) {
            return $this->ReportError('Invalid status data');
        }

        $this->Process(function (int $now) use ($key, $level, $message, $TargetID): void {
            $this->UpsertStatus($key, $level, $message, $TargetID, self::SOURCE_SCRIPT, $now, true);
        });

        return true;
    }

    public function ClearStatusItem(string $Key): bool
    {
        $key = trim($Key);
        if ($key === '' || $this->IsReservedKey($key)) {
            return false;
        }

        return (bool) $this->Process(function (int $now) use ($key): bool {
            $index = $this->NormalizeID($key);
            if (!isset($this->statuses[$index]) || !is_array($this->statuses[$index])) {
                return false;
            }
            $this->ClearStatusEntry($index, $now);
            return true;
        });
    }

    public function AddMessage(
        string $LevelID,
        string $Message,
        int $TargetID,
        int $RetentionDays,
        string $Identifier
    ): int {
        $message = trim($Message);
        $identifier = trim($Identifier);
        $level = $this->FindLevel($LevelID);
        $TargetID = $this->NormalizeObjectID($TargetID);

        if ($message === '' || $level === null || !$this->IsValidTarget($TargetID) || $RetentionDays < -1) {
            $this->ReportError('Invalid message data');
            return 0;
        }

        return (int) $this->Process(
            fn(int $now): int => $this->AddMessageEntry(
                $level,
                $message,
                $TargetID,
                $RetentionDays,
                $identifier,
                self::SOURCE_SCRIPT,
                $now
            )
        );
    }

    public function DeleteMessage(int $Sequence): bool
    {
        if ($Sequence <= 0) {
            return false;
        }

        return (bool) $this->Process(function (int $now) use ($Sequence): bool {
            foreach ($this->history as $position => $entry) {
                if (
                    ($entry['Type'] ?? '') === self::TYPE_MESSAGE &&
                    (int) ($entry['Sequence'] ?? 0) === $Sequence
                ) {
                    $this->MarkMessageDeleted($position, $now);
                    return true;
                }
            }
            return false;
        });
    }

    public function DeleteMessagesByIdentifier(string $Identifier): int
    {
        $identifier = $this->NormalizeID(trim($Identifier));
        if ($identifier === '') {
            return 0;
        }

        return (int) $this->Process(function (int $now) use ($identifier): int {
            $count = 0;
            foreach ($this->history as $position => $entry) {
                if (
                    ($entry['Type'] ?? '') === self::TYPE_MESSAGE &&
                    $this->NormalizeID((string) ($entry['Identifier'] ?? '')) === $identifier &&
                    $this->MarkMessageDeleted($position, $now)
                ) {
                    $count++;
                }
            }
            return $count;
        });
    }

    public function GetCurrentItems(): string
    {
        return $this->EncodeJSON($this->ReadData(fn(int $now): array => $this->BuildCurrentItems($now)));
    }

    public function GetHistory(): string
    {
        return $this->EncodeJSON($this->ReadData(fn(int $now): array => array_values($this->history)));
    }

    public function GetCount(string $LevelID): int
    {
        $level = $this->NormalizeID(trim($LevelID));
        $items = $this->ReadData(fn(int $now): array => $this->BuildCurrentItems($now));

        if ($level === '') {
            return count($items);
        }

        return count(array_filter(
            $items,
            fn(array $item): bool => $this->NormalizeID((string) $item['Level']) === $level
        ));
    }

    public function GetHighestLevel(): string
    {
        return $this->HighestLevel($this->ReadData(fn(int $now): array => $this->BuildCurrentItems($now)));
    }

    public function ClearHistory(): void
    {
        $this->Process(function (int $now): void {
            $this->history = [];
        }, true);
    }

    // ------------------------------------------------------------------
    // Locked processing
    // ------------------------------------------------------------------

    private function Initialize(): void
    {
        if ($this->GetLevels() === null) {
            $this->SetStatus(self::STATUS_INVALID_LEVELS);
            $this->SetTimerInterval('Maintenance', 0);
            $this->SetBuffer('TimerDue', '0');
            return;
        }

        $problems = $this->CollectRules()['Problems'];
        $script = $this->GetNotificationScript();
        if ($script !== self::NO_OBJECT && !IPS_ScriptExists($script)) {
            $problems[] = $this->Translate('Notification script does not exist');
        }
        foreach ($problems as $problem) {
            $this->LogMessage($problem, KL_WARNING);
        }
        $this->SetStatus($problems === [] ? self::STATUS_ACTIVE : self::STATUS_INVALID_RULES);

        $this->Process(null, true, false, true);
    }

    /**
     * Loads all data, runs the optional action, evaluates the rules and
     * writes back what changed. Outputs are only republished when data
     * changed or a full refresh is requested.
     */
    private function Process(
        ?callable $Action = null,
        bool $Full = false,
        bool $ForceRender = false,
        bool $ResetTimer = false
    ): mixed {
        $this->EnterDataLock();
        try {
            $now = time();
            $this->LoadData();

            $result = $Action !== null ? $Action($now) : null;

            if ($this->GetLevels() !== null && IPS_GetKernelRunlevel() === KR_READY) {
                $this->EvaluateRules($now);
            }

            $this->Commit($now, $Full, $ForceRender, $ResetTimer);
            $notifications = $this->notifications;
        } finally {
            $this->LeaveDataLock();
        }

        // Outside the lock: the script may call NBOARD_* functions itself.
        $this->DispatchNotifications($notifications);

        return $result;
    }

    private function ReadData(callable $Reader): mixed
    {
        $this->EnterDataLock();
        try {
            $this->LoadData();
            return $Reader(time());
        } finally {
            $this->LeaveDataLock();
        }
    }

    private function LoadData(): void
    {
        $this->statuses = $this->DecodeObject($this->ReadAttributeString('Statuses'));
        $history = json_decode($this->ReadAttributeString('HistoryData'), true);
        $this->history = is_array($history) && array_is_list($history) ? $history : [];
        $this->ruleState = $this->DecodeObject($this->ReadAttributeString('RuleState'));
        $this->nextDue = null;
        $this->notifications = [];
    }

    private function Commit(int $Now, bool $Full, bool $ForceRender, bool $ResetTimer): void
    {
        $this->PurgeHistory($Now);

        $historyJSON = $this->EncodeJSON(array_values($this->history));
        $statusesChanged = $this->WriteAttributeIfChanged('Statuses', $this->EncodeObject($this->statuses));
        $historyChanged = $this->WriteAttributeIfChanged('HistoryData', $historyJSON);
        $stateChanged = $this->WriteAttributeIfChanged('RuleState', $this->EncodeObject($this->ruleState));

        if ($statusesChanged || $historyChanged || $stateChanged || $Full || $ForceRender) {
            $items = $this->BuildCurrentItems($Now);

            // History first: events on CurrentItems can inspect the matching history.
            $this->SetValueIfChanged('History', $historyJSON);
            $this->SetValueIfChanged('CurrentItems', $this->EncodeJSON($items));

            if ($this->ReadPropertyBoolean('SummaryVariables')) {
                $this->SetValueIfChanged('ActiveCount', count($items));
                $this->SetValueIfChanged('HighestLevel', $this->HighestLevel($items));
            }

            $render = $this->EncodeJSON(['Type' => 'Render', 'Data' => $this->BuildRenderData($items)]);
            $hash = md5($render);
            if ($ForceRender || $this->GetBuffer('RenderHash') !== $hash) {
                $this->SetBuffer('RenderHash', $hash);
                $this->UpdateVisualizationValue($render);
            }
        }

        $this->ScheduleMaintenance($Now, $ResetTimer);
    }

    /**
     * The timer runs at most every 60 seconds and earlier when a rule
     * delay or a message expiry is due. It is only rearmed when the new
     * due time is earlier, so frequent variable updates cannot starve it.
     */
    private function ScheduleMaintenance(int $Now, bool $Reset): void
    {
        $due = $this->nextDue;
        foreach ($this->history as $entry) {
            $expiry = $this->GetMessageExpiry($entry);
            if ($expiry !== null && $expiry > $Now && !($entry['Deleted'] ?? false)) {
                $due = min($due ?? $expiry, $expiry);
            }
        }

        $seconds = self::MAX_TIMER_SECONDS;
        if ($due !== null) {
            $seconds = max(1, min($seconds, $due - $Now));
        }

        $target = $Now + $seconds;
        $scheduled = (int) $this->GetBuffer('TimerDue');
        if ($Reset || $scheduled <= $Now || $target < $scheduled) {
            $this->SetTimerInterval('Maintenance', $seconds * 1000);
            $this->SetBuffer('TimerDue', (string) $target);
        }
    }

    private function EnterDataLock(): void
    {
        if (!IPS_SemaphoreEnter('NBOARD.Data.' . $this->InstanceID, 5000)) {
            throw new RuntimeException($this->Translate('Notice data is busy. Please retry.'));
        }
    }

    private function LeaveDataLock(): void
    {
        IPS_SemaphoreLeave('NBOARD.Data.' . $this->InstanceID);
    }

    // ------------------------------------------------------------------
    // Statuses and messages
    // ------------------------------------------------------------------

    /**
     * Rule statuses pass $LogTextChanges = false: a changing {value}
     * updates the text silently instead of filling the history.
     */
    private function UpsertStatus(
        string $Key,
        array $Level,
        string $Message,
        int $TargetID,
        string $Source,
        int $Now,
        bool $LogTextChanges,
        array $Extra = []
    ): void {
        $index = $this->NormalizeID($Key);
        $existing = $this->statuses[$index] ?? null;

        if (!is_array($existing)) {
            $status = [
                'Type'     => self::TYPE_STATUS,
                'Key'      => $Key,
                'Source'   => $Source,
                'Level'    => $Level['ID'],
                'Message'  => $Message,
                'Created'  => $Now,
                'Updated'  => $Now,
                'Sequence' => $this->NextSequence(),
                'TargetID' => $TargetID
            ] + $Extra;
            $this->statuses[$index] = $status;
            $this->AppendStatusHistory($status, self::EVENT_SET, $Now);
            return;
        }

        $previous = $existing;
        $levelChanged = $existing['Level'] !== $Level['ID'];
        $textChanged = $existing['Message'] !== $Message;
        $targetChanged = $this->NormalizeObjectID((int) ($existing['TargetID'] ?? self::NO_OBJECT)) !== $TargetID;

        $existing = array_merge($existing, [
            'Level'    => $Level['ID'],
            'Message'  => $Message,
            'TargetID' => $TargetID
        ], $Extra);

        if ($levelChanged || $textChanged || $targetChanged) {
            $existing['Updated'] = $Now;
        }
        $this->statuses[$index] = $existing;

        if ($levelChanged || $targetChanged || ($textChanged && $LogTextChanges)) {
            $this->AppendStatusHistory($existing, self::EVENT_CHANGED, $Now, $previous);
        }
    }

    private function ClearStatusEntry(string $Index, int $Now): void
    {
        $status = $this->statuses[$Index];
        unset($this->statuses[$Index]);

        $status['Resolved'] = $Now;
        $status['Duration'] = max(0, $Now - (int) ($status['Created'] ?? $Now));
        $this->AppendStatusHistory($status, self::EVENT_CLEARED, $Now);
    }

    private function AppendStatusHistory(array $Status, string $Event, int $Timestamp, ?array $Previous = null): void
    {
        $entry = $Status;
        $entry['Type'] = self::TYPE_STATUS;
        $entry['Message'] = $this->ResolveDynamicText($Status, $Timestamp);
        $entry['Event'] = $Event;
        $entry['Sequence'] = $this->NextSequence();
        $entry['EventTime'] = $Timestamp;
        if ($Previous !== null) {
            $entry['PreviousLevel'] = (string) ($Previous['Level'] ?? '');
            $entry['PreviousMessage'] = $this->ResolveDynamicText($Previous, $Timestamp);
            $entry['PreviousTargetID'] = $this->NormalizeObjectID((int) ($Previous['TargetID'] ?? self::NO_OBJECT));
        }
        $this->history[] = $entry;
        $this->QueueNotification($Event, $entry);
    }

    private function AddMessageEntry(
        array $Level,
        string $Message,
        int $TargetID,
        int $RetentionDays,
        string $Identifier,
        string $Source,
        int $Now
    ): int {
        $sequence = $this->NextSequence();
        $entry = [
            'Type'          => self::TYPE_MESSAGE,
            'Source'        => $Source,
            'Sequence'      => $sequence,
            'Identifier'    => $Identifier,
            'Level'         => $Level['ID'],
            'Message'       => $Message,
            'Created'       => $Now,
            'TargetID'      => $TargetID,
            'RetentionDays' => $RetentionDays,
            'Deleted'       => false,
            'DeletedAt'     => 0
        ];
        $this->history[] = $entry;
        $this->QueueNotification(self::TYPE_MESSAGE, $entry);
        return $sequence;
    }

    /** Returns true when the message was visible-state "not deleted" before. */
    private function MarkMessageDeleted(int $Position, int $Now): bool
    {
        if ($this->history[$Position]['Deleted'] ?? false) {
            return false;
        }
        $this->history[$Position]['Deleted'] = true;
        $this->history[$Position]['DeletedAt'] = $Now;
        return true;
    }

    private function PurgeHistory(int $Now): void
    {
        $retentionDays = max(0, $this->ReadPropertyInteger('HistoryRetentionDays'));
        $maximum = max(0, $this->ReadPropertyInteger('MaximumHistoryEntries'));
        $history = $this->history;

        if ($retentionDays > 0) {
            $threshold = $Now - ($retentionDays * 86400);
            $history = array_values(array_filter(
                $history,
                fn(array $entry): bool =>
                    $this->GetHistoryTimestamp($entry) >= $threshold || $this->IsVisibleMessage($entry, $Now)
            ));
        }

        if ($maximum > 0 && count($history) > $maximum) {
            $remove = count($history) - $maximum;
            $trimmed = [];
            foreach ($history as $entry) {
                if ($remove > 0 && !$this->IsVisibleMessage($entry, $Now)) {
                    $remove--;
                    continue;
                }
                $trimmed[] = $entry;
            }
            $history = $trimmed;
        }

        $this->history = $history;
    }

    private function GetMessageExpiry(array $Entry): ?int
    {
        if (($Entry['Type'] ?? '') !== self::TYPE_MESSAGE) {
            return null;
        }

        $retention = (int) ($Entry['RetentionDays'] ?? 0);
        if ($retention === 0) {
            $retention = max(0, $this->ReadPropertyInteger('MessageRetentionDays'));
        }
        if ($retention <= 0) {
            return null;
        }

        return (int) ($Entry['Created'] ?? 0) + ($retention * 86400);
    }

    private function IsVisibleMessage(array $Entry, int $Now): bool
    {
        if (($Entry['Type'] ?? '') !== self::TYPE_MESSAGE || ($Entry['Deleted'] ?? false)) {
            return false;
        }

        $expiry = $this->GetMessageExpiry($Entry);
        return $expiry === null || $expiry > $Now;
    }

    private function GetHistoryTimestamp(array $Entry): int
    {
        if (($Entry['Type'] ?? '') === self::TYPE_STATUS) {
            return (int) ($Entry['EventTime'] ?? $Entry['Created'] ?? 0);
        }

        return (int) ($Entry['Created'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------

    /**
     * Reads the three rule lists. Inactive rows are ignored, invalid rows
     * are reported in Problems and skipped.
     */
    private function CollectRules(): array
    {
        $rules = [];
        $problems = [];
        $variables = [];
        $instances = [];
        $usedKeys = [];

        foreach ($this->ReadList('ConditionRules') as $row => $entry) {
            if (!($entry['Active'] ?? true)) {
                continue;
            }

            $condition = trim((string) ($entry['Condition'] ?? ''));
            $decoded = json_decode($condition, true);
            $mode = (int) ($entry['Mode'] ?? self::MODE_STATUS);
            $sources = is_array($decoded) ? $this->ExtractVariableIDs($decoded) : [];
            $missing = array_filter($sources, fn(int $id): bool => !IPS_VariableExists($id));
            $placeholderID = $this->NormalizeObjectID((int) ($entry['PlaceholderVariableID'] ?? self::NO_OBJECT));

            $rule = $this->BuildRuleBase($entry);
            $problem = $this->ValidateRuleBase($rule);
            if (!is_array($decoded) || $decoded === []) {
                $problem = 'no condition defined';
            } elseif ($missing !== []) {
                $problem = 'condition variable does not exist';
            } elseif ($placeholderID !== self::NO_OBJECT && !IPS_VariableExists($placeholderID)) {
                $problem = 'placeholder variable does not exist';
            } elseif (!in_array($mode, [self::MODE_STATUS, self::MODE_MESSAGE], true)) {
                $problem = 'invalid mode';
            }

            if ($problem !== null) {
                $problems[] = $this->DescribeProblem('Condition rule', $row, $problem);
                continue;
            }

            $rule['Key'] = $this->UniqueKey(
                self::SOURCE_CONDITION . ':' . substr(md5($condition . '|' . $mode), 0, 12),
                $usedKeys
            );
            $rule['Source'] = self::SOURCE_CONDITION;
            $rule['Mode'] = $mode;
            $rule['Condition'] = $condition;
            // Placeholders use the chosen variable, otherwise the first variable of the condition.
            $rule['SubjectID'] = $placeholderID !== self::NO_OBJECT ? $placeholderID : ($sources[0] ?? self::NO_OBJECT);
            $rules[] = $rule;
            $variables = array_merge($variables, $sources);
            if ($placeholderID !== self::NO_OBJECT) {
                // Keeps {value} current even if the variable is not part of the condition.
                $variables[] = $placeholderID;
            }
        }

        foreach ($this->ReadList('StaleRules') as $row => $entry) {
            if (!($entry['Active'] ?? true)) {
                continue;
            }

            $variableID = (int) ($entry['VariableID'] ?? self::NO_OBJECT);
            $maxAge = (int) ($entry['MaxAge'] ?? 0);

            $rule = $this->BuildRuleBase($entry);
            $problem = $this->ValidateRuleBase($rule);
            if ($variableID <= self::NO_OBJECT || !IPS_VariableExists($variableID)) {
                $problem = 'variable does not exist';
            } elseif ($maxAge < 1) {
                $problem = 'maximum age must be at least one minute';
            }

            if ($problem !== null) {
                $problems[] = $this->DescribeProblem('Update rule', $row, $problem);
                continue;
            }

            $rule['Key'] = $this->UniqueKey(self::SOURCE_STALE . ':' . $variableID, $usedKeys);
            $rule['Source'] = self::SOURCE_STALE;
            $rule['Mode'] = self::MODE_STATUS;
            $rule['Delay'] = 0;
            $rule['MaxAge'] = $maxAge * 60;
            $rule['SubjectID'] = $variableID;
            $rules[] = $rule;
            $variables[] = $variableID;
        }

        foreach ($this->ReadList('InstanceRules') as $row => $entry) {
            if (!($entry['Active'] ?? true)) {
                continue;
            }

            $instanceID = (int) ($entry['InstanceID'] ?? self::NO_OBJECT);

            $rule = $this->BuildRuleBase($entry);
            $problem = $this->ValidateRuleBase($rule);
            if ($instanceID <= self::NO_OBJECT || !IPS_InstanceExists($instanceID)) {
                $problem = 'instance does not exist';
            }

            if ($problem !== null) {
                $problems[] = $this->DescribeProblem('Instance rule', $row, $problem);
                continue;
            }

            $rule['Key'] = $this->UniqueKey(self::SOURCE_INSTANCE . ':' . $instanceID, $usedKeys);
            $rule['Source'] = self::SOURCE_INSTANCE;
            $rule['Mode'] = self::MODE_STATUS;
            $rule['SubjectID'] = $instanceID;
            $rules[] = $rule;
            $instances[] = $instanceID;
        }

        return [
            'Rules'     => $rules,
            'Problems'  => $problems,
            'Variables' => array_values(array_unique($variables)),
            'Instances' => array_values(array_unique($instances))
        ];
    }

    private function BuildRuleBase(array $Entry): array
    {
        return [
            'Level'    => $this->FindLevel((string) ($Entry['Level'] ?? '')),
            'Text'     => trim((string) ($Entry['Text'] ?? '')),
            'Delay'    => max(0, (int) ($Entry['Delay'] ?? 0)),
            'TargetID' => $this->NormalizeObjectID((int) ($Entry['TargetID'] ?? self::NO_OBJECT))
        ];
    }

    private function ValidateRuleBase(array $Rule): ?string
    {
        if ($Rule['Level'] === null) {
            return 'unknown level';
        }
        if ($Rule['Text'] === '') {
            return 'empty text';
        }
        if (!$this->IsValidTarget($Rule['TargetID'])) {
            return 'target does not exist';
        }
        return null;
    }

    private function DescribeProblem(string $Kind, int $Row, string $Problem): string
    {
        return sprintf('%s %d: %s', $this->Translate($Kind), $Row + 1, $this->Translate($Problem));
    }

    private function EvaluateRules(int $Now): void
    {
        $seen = [];

        foreach ($this->CollectRules()['Rules'] as $rule) {
            $seen[$rule['Key']] = true;

            switch ($rule['Source']) {
                case self::SOURCE_CONDITION:
                    try {
                        $passing = IPS_IsConditionPassing($rule['Condition']);
                    } catch (Throwable $exception) {
                        $passing = false;
                    }
                    $this->ApplyRule($rule, $passing, $Now);
                    break;

                case self::SOURCE_STALE:
                    // VariableUpdated is persisted by Symcon, so the age survives a restart.
                    $updated = (int) (IPS_GetVariable($rule['SubjectID'])['VariableUpdated'] ?? 0);
                    $due = $updated + $rule['MaxAge'];
                    $stale = $updated === 0 || $Now >= $due;
                    if (!$stale) {
                        $this->ScheduleDue($due);
                    }
                    $this->ApplyRule($rule, $stale, $Now, ['AgeSince' => $updated]);
                    break;

                case self::SOURCE_INSTANCE:
                    $status = (int) (IPS_GetInstance($rule['SubjectID'])['InstanceStatus'] ?? 0);
                    $rule['StatusCode'] = $status;
                    if ($status >= 200 && str_contains($rule['Text'], '{statustext}')) {
                        $rule['StatusText'] = $this->GetInstanceStatusText($rule['SubjectID'], $status);
                    }
                    $this->ApplyRule($rule, $status >= 200, $Now, ['InstanceStatus' => $status]);
                    break;
            }
        }

        // Statuses of removed, deactivated or invalid rules are resolved.
        foreach ($this->statuses as $index => $status) {
            $source = (string) ($status['Source'] ?? self::SOURCE_SCRIPT);
            if ($source !== self::SOURCE_SCRIPT && !isset($seen[(string) ($status['Key'] ?? '')])) {
                $this->ClearStatusEntry((string) $index, $Now);
            }
        }

        $this->ruleState = array_intersect_key($this->ruleState, $seen);
    }

    /**
     * "Since" (first time the condition was met) is persisted, so a
     * running delay continues correctly after a restart. An already
     * active status stays active while its condition holds.
     */
    private function ApplyRule(array $Rule, bool $Condition, int $Now, array $Extra = []): void
    {
        $key = $Rule['Key'];
        $index = $this->NormalizeID($key);
        $active = isset($this->statuses[$index]);

        if (!$Condition) {
            unset($this->ruleState[$key]);
            if ($Rule['Mode'] === self::MODE_STATUS && $active) {
                $this->ClearStatusEntry($index, $Now);
            }
            return;
        }

        $state = $this->ruleState[$key] ?? [];
        $since = (int) ($state['Since'] ?? 0);
        $fired = (bool) ($state['Fired'] ?? false);
        if ($since === 0) {
            $since = $Now;
        }

        $due = $since + $Rule['Delay'];
        $triggered = $active || $Now >= $due;
        if (!$triggered) {
            $this->ScheduleDue($due);
        }

        if ($Rule['Mode'] === self::MODE_STATUS) {
            if ($triggered) {
                $this->UpsertStatus(
                    $key,
                    $Rule['Level'],
                    $this->ResolveRuleText($Rule),
                    $Rule['TargetID'],
                    $Rule['Source'],
                    $Now,
                    false,
                    $Extra
                );
            }
        } elseif ($triggered && !$fired) {
            $this->AddMessageEntry(
                $Rule['Level'],
                $this->ResolveRuleText($Rule),
                $Rule['TargetID'],
                0,
                $key,
                $Rule['Source'],
                $Now
            );
            $fired = true;
        }

        $this->ruleState[$key] = ['Since' => $since, 'Fired' => $fired];
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    private function GetNotificationScript(): int
    {
        return $this->NormalizeObjectID($this->ReadPropertyInteger('NotificationScript'));
    }

    /**
     * Collects events that are reported to the notification script:
     * status set, changed (logged changes only, not silent text updates),
     * cleared, and new messages. Deleting messages is not reported.
     */
    private function QueueNotification(string $Event, array $Entry): void
    {
        if ($this->GetNotificationScript() === self::NO_OBJECT) {
            return;
        }

        $level = $this->FindLevel((string) ($Entry['Level'] ?? ''));
        if ($level !== null && !$level['Notify']) {
            return;
        }

        $this->notifications[] = [
            'NBOARD_INSTANCE'   => $this->InstanceID,
            'NBOARD_EVENT'      => $Event,
            'NBOARD_TYPE'       => (string) ($Entry['Type'] ?? ''),
            'NBOARD_SOURCE'     => (string) ($Entry['Source'] ?? self::SOURCE_SCRIPT),
            'NBOARD_LEVEL'      => (string) ($Entry['Level'] ?? ''),
            'NBOARD_PRIORITY'   => $level['Priority'] ?? 0,
            'NBOARD_MESSAGE'    => (string) ($Entry['Message'] ?? ''),
            'NBOARD_KEY'        => (string) ($Entry['Key'] ?? ''),
            'NBOARD_IDENTIFIER' => (string) ($Entry['Identifier'] ?? ''),
            'NBOARD_SEQUENCE'   => (int) ($Entry['Sequence'] ?? 0),
            'NBOARD_TARGET'     => $this->NormalizeObjectID((int) ($Entry['TargetID'] ?? self::NO_OBJECT)),
            'NBOARD_ITEM'       => $this->EncodeJSON($Entry)
        ];
    }

    /** Asynchronous, one call per event: nothing is lost and the module never waits. */
    private function DispatchNotifications(array $Notifications): void
    {
        $script = $this->GetNotificationScript();
        if ($Notifications === [] || $script === self::NO_OBJECT || !IPS_ScriptExists($script)) {
            return;
        }

        foreach ($Notifications as $parameters) {
            IPS_RunScriptEx($script, $parameters);
        }
    }

    private function ScheduleDue(int $Due): void
    {
        $this->nextDue = $this->nextDue === null ? $Due : min($this->nextDue, $Due);
    }

    private function ResolveRuleText(array $Rule): string
    {
        $subject = (int) ($Rule['SubjectID'] ?? self::NO_OBJECT);
        $replace = [
            '{name}'   => '',
            '{parent}' => '',
            '{value}'  => '',
            '{status}' => isset($Rule['StatusCode']) ? (string) $Rule['StatusCode'] : '',
            '{statustext}' => (string) ($Rule['StatusText'] ?? ($Rule['StatusCode'] ?? ''))
        ];

        if ($subject > self::NO_OBJECT && IPS_ObjectExists($subject)) {
            $replace['{name}'] = IPS_GetName($subject);
            $parent = IPS_GetParent($subject);
            if ($parent > 0) {
                $replace['{parent}'] = IPS_GetName($parent);
            }
            if (IPS_VariableExists($subject)) {
                $replace['{value}'] = GetValueFormatted($subject);
            }
        }

        $text = trim(strtr($Rule['Text'], $replace));
        return $text !== '' ? $text : $Rule['Key'];
    }

    /** {age} is resolved at display time, so it neither spams the history nor goes stale. */
    /**
     * Reads the status caption from the other module's configuration form
     * and translates it with the translations shipped in that form.
     * Only called while an error is active and the text uses {statustext};
     * cached per instance and code, because some modules do work
     * (e.g. network discovery) in GetConfigurationForm.
     */
    private function GetInstanceStatusText(int $InstanceID, int $Status): string
    {
        $cache = $this->DecodeObject($this->GetBuffer('StatusTexts'));
        $cacheKey = $InstanceID . ':' . $Status;
        if (isset($cache[$cacheKey]) && is_string($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $text = (string) $Status;
        try {
            $form = json_decode(IPS_GetConfigurationForm($InstanceID), true);
        } catch (Throwable $exception) {
            $form = null;
        }

        if (is_array($form)) {
            foreach ($form['status'] ?? [] as $entry) {
                if (is_array($entry) && (int) ($entry['code'] ?? 0) === $Status && trim((string) ($entry['caption'] ?? '')) !== '') {
                    $text = $this->TranslateForeign(trim((string) $entry['caption']), $form['translations'] ?? []);
                    break;
                }
            }
        }

        $cache[$cacheKey] = $text;
        $this->SetBuffer('StatusTexts', $this->EncodeObject($cache));
        return $text;
    }

    /** Uses the language of this module's own translation ("__language" in locale.json). */
    private function TranslateForeign(string $Text, mixed $Translations): string
    {
        $language = $this->Translate('__language');
        if ($language === '__language' || !is_array($Translations)) {
            return $Text;
        }

        $candidates = [];
        foreach ($Translations as $code => $table) {
            if (is_array($table) && ($code === $language || str_starts_with((string) $code, $language . '_'))) {
                $candidates[$code === $language ? 0 : 1][] = $table;
            }
        }
        ksort($candidates);
        foreach ($candidates as $tables) {
            foreach ($tables as $table) {
                if (isset($table[$Text]) && is_string($table[$Text]) && $table[$Text] !== '') {
                    return $table[$Text];
                }
            }
        }
        return $Text;
    }

    private function ResolveDynamicText(array $Item, int $Now): string
    {
        $message = (string) ($Item['Message'] ?? '');
        if (!array_key_exists('AgeSince', $Item) || !str_contains($message, '{age}')) {
            return $message;
        }

        $since = (int) $Item['AgeSince'];
        $age = $since > 0 ? $this->FormatDuration($Now - $since) : $this->Translate('never');
        return str_replace('{age}', $age, $message);
    }

    private function FormatDuration(int $Seconds): string
    {
        $minutes = intdiv(max(0, $Seconds), 60);
        if ($minutes < 60) {
            return sprintf('%d min', $minutes);
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return sprintf('%d h %d min', $hours, $minutes % 60);
        }

        return sprintf($this->Translate('%d d %d h'), intdiv($hours, 24), $hours % 24);
    }

    private function ExtractVariableIDs(array $Data): array
    {
        $ids = [];
        foreach ($Data as $key => $value) {
            if ($key === 'variableID' && is_int($value) && $value > self::NO_OBJECT) {
                $ids[] = $value;
            } elseif (is_array($value)) {
                $ids = array_merge($ids, $this->ExtractVariableIDs($value));
            }
        }
        return array_values(array_unique($ids));
    }

    private function UniqueKey(string $Base, array &$Used): string
    {
        $key = $Base;
        $suffix = 2;
        while (isset($Used[$key])) {
            $key = $Base . '.' . $suffix++;
        }
        $Used[$key] = true;
        return $key;
    }

    private function IsReservedKey(string $Key): bool
    {
        $key = $this->NormalizeID($Key);
        foreach ([self::SOURCE_CONDITION, self::SOURCE_STALE, self::SOURCE_INSTANCE] as $source) {
            if (str_starts_with($key, $source . ':')) {
                return true;
            }
        }
        return false;
    }

    private function ReadList(string $Property): array
    {
        $list = json_decode($this->ReadPropertyString($Property), true);
        if (!is_array($list) || !array_is_list($list)) {
            return [];
        }
        return array_values(array_filter($list, 'is_array'));
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    private function BuildCurrentItems(int $Now): array
    {
        $items = array_values($this->statuses);
        foreach ($this->history as $entry) {
            if ($this->IsVisibleMessage($entry, $Now)) {
                $items[] = $entry;
            }
        }

        $levelMap = [];
        foreach ($this->GetLevels() ?? [] as $level) {
            $levelMap[$this->NormalizeID($level['ID'])] = $level;
        }

        $dateFormat = $this->ReadPropertyString('DateFormat');
        $result = [];
        foreach ($items as $item) {
            $level = $levelMap[$this->NormalizeID((string) ($item['Level'] ?? ''))] ?? [
                'ID'       => (string) ($item['Level'] ?? '?'),
                'Priority' => PHP_INT_MAX,
                'Color'    => '#808080'
            ];

            $item['Message'] = $this->ResolveDynamicText($item, $Now);
            $item['Level'] = $level['ID'];
            $item['Priority'] = $level['Priority'];
            $item['Color'] = $level['Color'];
            $item['TimestampText'] = date($dateFormat, (int) ($item['Created'] ?? $Now));
            $item['Deletable'] = ($item['Type'] ?? '') === self::TYPE_MESSAGE;
            $result[] = $item;
        }

        usort($result, fn(array $left, array $right): int => $this->CompareItems($left, $right));

        return $result;
    }

    private function HighestLevel(array $Items): string
    {
        $best = null;
        foreach ($Items as $item) {
            if ($best === null || (int) $item['Priority'] < (int) $best['Priority']) {
                $best = $item;
            }
        }
        return $best === null ? '' : (string) $best['Level'];
    }

    private function CompareItems(array $Left, array $Right): int
    {
        $leftType = (string) ($Left['Type'] ?? '');
        $rightType = (string) ($Right['Type'] ?? '');
        $grouping = $this->ReadPropertyInteger('Grouping');

        if ($grouping === 2) {
            return $this->ComparePriorityThenNewest($Left, $Right);
        }

        if ($leftType !== $rightType) {
            $statusFirst = $grouping === 0;
            if ($leftType === self::TYPE_STATUS) {
                return $statusFirst ? -1 : 1;
            }
            return $statusFirst ? 1 : -1;
        }

        if ($leftType === self::TYPE_STATUS) {
            switch ($this->ReadPropertyInteger('StatusSorting')) {
                case 1:
                    return $this->CompareNewest($Left, $Right);
                case 2:
                    return $this->CompareOldest($Left, $Right);
                default:
                    return $this->ComparePriorityThenNewest($Left, $Right);
            }
        }

        switch ($this->ReadPropertyInteger('MessageSorting')) {
            case 1:
                return $this->CompareOldest($Left, $Right);
            case 2:
                return $this->ComparePriorityThenNewest($Left, $Right);
            default:
                return $this->CompareNewest($Left, $Right);
        }
    }

    private function ComparePriorityThenNewest(array $Left, array $Right): int
    {
        $priority = (int) ($Left['Priority'] ?? PHP_INT_MAX) <=> (int) ($Right['Priority'] ?? PHP_INT_MAX);
        return $priority !== 0 ? $priority : $this->CompareNewest($Left, $Right);
    }

    private function CompareNewest(array $Left, array $Right): int
    {
        $created = (int) ($Right['Created'] ?? 0) <=> (int) ($Left['Created'] ?? 0);
        return $created !== 0
            ? $created
            : (int) ($Right['Sequence'] ?? 0) <=> (int) ($Left['Sequence'] ?? 0);
    }

    private function CompareOldest(array $Left, array $Right): int
    {
        $created = (int) ($Left['Created'] ?? 0) <=> (int) ($Right['Created'] ?? 0);
        return $created !== 0
            ? $created
            : (int) ($Left['Sequence'] ?? 0) <=> (int) ($Right['Sequence'] ?? 0);
    }

    private function BuildRenderData(array $Items): array
    {
        return [
            'Items'         => $Items,
            'Grouped'       => $this->ReadPropertyInteger('Grouping') !== 2,
            'ShowTimestamp' => $this->ReadPropertyBoolean('ShowTimestamp'),
            'EmptyText'     => $this->ReadPropertyString('EmptyText')
        ];
    }

    private function SetValueIfChanged(string $Ident, mixed $Value): void
    {
        $id = $this->FindOwnVariable($Ident);
        if ($id > 0 && GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }

    // ------------------------------------------------------------------
    // Configuration helpers
    // ------------------------------------------------------------------

    private function ConfigureVisualization(): void
    {
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN')
            ? constant('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') : 1);
    }

    private function RegisterSourcesAndReferences(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage((int) $senderID, (int) $message);
            }
        }
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference((int) $referenceID);
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        foreach (['CurrentItems', 'History', 'ActiveCount', 'HighestLevel'] as $ident) {
            $id = $this->FindOwnVariable($ident);
            if ($id > 0) {
                $this->RegisterReference($id);
            }
        }

        $script = $this->GetNotificationScript();
        if ($script !== self::NO_OBJECT && IPS_ScriptExists($script)) {
            $this->RegisterReference($script);
        }

        $rules = $this->CollectRules();
        foreach ($rules['Variables'] as $variableID) {
            $this->RegisterMessage($variableID, VM_UPDATE);
            $this->RegisterReference($variableID);
        }
        foreach ($rules['Instances'] as $instanceID) {
            $this->RegisterMessage($instanceID, IM_CHANGESTATUS);
            $this->RegisterReference($instanceID);
        }
    }

    private function PrepareFormElements(array $Elements, array $Options, string $DefaultLevel, array $DefaultTexts): array
    {
        foreach ($Elements as &$element) {
            if (isset($element['items']) && is_array($element['items'])) {
                $element['items'] = $this->PrepareFormElements($element['items'], $Options, $DefaultLevel, $DefaultTexts);
            }

            $name = (string) ($element['name'] ?? '');
            if (($element['type'] ?? '') !== 'List' || !isset($DefaultTexts[$name])) {
                continue;
            }

            foreach ($element['columns'] as &$column) {
                if ($column['name'] === 'Level') {
                    $column['add'] = $DefaultLevel;
                    $column['edit']['options'] = $Options;
                } elseif ($column['name'] === 'Text') {
                    $column['add'] = $DefaultTexts[$name];
                }
            }
            unset($column);
        }
        unset($element);

        return $Elements;
    }

    private function GetLevels(): ?array
    {
        $decoded = json_decode($this->ReadPropertyString('Levels'), true);
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) === 0) {
            return null;
        }

        $levels = [];
        $usedIDs = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            $id = trim((string) ($entry['ID'] ?? ''));
            $priority = $entry['Priority'] ?? null;
            $color = $this->NormalizeColor($entry['Color'] ?? null);
            $normalizedID = $this->NormalizeID($id);

            if (
                $id === '' ||
                isset($usedIDs[$normalizedID]) ||
                !is_int($priority) ||
                $priority < 1 ||
                $color === null
            ) {
                return null;
            }

            $usedIDs[$normalizedID] = true;
            $levels[] = [
                'ID'       => $id,
                'Priority' => $priority,
                'Color'    => $color,
                'Notify'   => (bool) ($entry['Notify'] ?? true)
            ];
        }

        return $levels;
    }

    private function FindLevel(string $ID): ?array
    {
        $normalizedID = $this->NormalizeID(trim($ID));
        foreach ($this->GetLevels() ?? [] as $level) {
            if ($this->NormalizeID($level['ID']) === $normalizedID) {
                return $level;
            }
        }
        return null;
    }

    private function NormalizeColor(mixed $Color): ?string
    {
        if (is_int($Color)) {
            return $Color >= 0 && $Color <= 0xFFFFFF ? sprintf('#%06X', $Color) : null;
        }

        if (!is_string($Color)) {
            return null;
        }

        $color = strtoupper(trim($Color));
        if (preg_match('/^#[0-9A-F]{6}$/', $color) === 1) {
            return $color;
        }

        if (ctype_digit($color)) {
            $numericColor = (int) $color;
            return $numericColor <= 0xFFFFFF ? sprintf('#%06X', $numericColor) : null;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Low-level helpers
    // ------------------------------------------------------------------

    private function IsValidTarget(int $TargetID): bool
    {
        return $TargetID === self::NO_OBJECT || IPS_ObjectExists($TargetID);
    }

    /** 1 is "no object"; 0 and negative values are accepted as the same. */
    private function NormalizeObjectID(int $ObjectID): int
    {
        return $ObjectID <= self::NO_OBJECT ? self::NO_OBJECT : $ObjectID;
    }

    private function NormalizeID(string $ID): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($ID, 'UTF-8') : strtolower($ID);
    }

    private function NextSequence(): int
    {
        $sequence = max(1, $this->ReadAttributeInteger('NextSequence'));
        $this->WriteAttributeInteger('NextSequence', $sequence + 1);
        return $sequence;
    }

    private function FindOwnVariable(string $Ident): int
    {
        $id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        return is_int($id) ? $id : 0;
    }

    private function WriteAttributeIfChanged(string $Name, string $Value): bool
    {
        if ($this->ReadAttributeString($Name) === $Value) {
            return false;
        }
        $this->WriteAttributeString($Name, $Value);
        return true;
    }

    private function DecodeObject(string $JSON): array
    {
        $decoded = json_decode($JSON, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Keeps keyed data a JSON object even when it is empty or numerically keyed. */
    private function EncodeObject(array $Value): string
    {
        return $this->EncodeJSON((object) $Value);
    }

    private function EncodeJSON(array|object $Value): string
    {
        return json_encode(
            $Value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function ReportError(string $Message): bool
    {
        trigger_error($this->Translate($Message), E_USER_WARNING);
        return false;
    }
}
