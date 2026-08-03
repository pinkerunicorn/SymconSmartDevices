<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';

class TedeeLock extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    use SmartHttp_Trait;
    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterWatchdog();
        $this->DA_RegisterAvailability(900); // Alarm priority: 2 (High)
        
        $this->RegisterPropertyString('BridgeIP', '');
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyBoolean('UseEncryptedToken', true);
        $this->RegisterPropertyInteger('LockID', 0);
        $this->RegisterPropertyString('SymconBaseURL', 'http://10.1.60.150:3777');
        
        $this->RegisterTimer('StatusUpdateTimer', 0, 'TEDEE_UpdateStatus($_IPS[\'TARGET\']);');
        
        $this->RegisterAttributeInteger('DetectedLockID', 0);

        $this->RegisterVariables();
    }
    private function RegisterVariables(): void
    {
        $stateIntervals = json_encode([
            [ 'IntervalMinValue' => 0, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Nicht kalibriert', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 1, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Kalibriert', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Gear', 'ColorActive' => true, 'ColorValue' => 0x0088FF, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 2, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Entsperrt', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'LockOpen', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 3, 'IntervalMaxValue' => 4, 'ConstantActive' => true, 'ConstantValue' => 'Halb gesperrt', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 4, 'IntervalMaxValue' => 5, 'ConstantActive' => true, 'ConstantValue' => 'Entsperrt...', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'LockOpen', 'ColorActive' => true, 'ColorValue' => 0xFF6600, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 5, 'IntervalMaxValue' => 6, 'ConstantActive' => true, 'ConstantValue' => 'Sperrt...', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'LockClosed', 'ColorActive' => true, 'ColorValue' => 0xFFCC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 6, 'IntervalMaxValue' => 7, 'ConstantActive' => true, 'ConstantValue' => 'Gesperrt', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'LockClosed', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 7, 'IntervalMaxValue' => 8, 'ConstantActive' => true, 'ConstantValue' => 'Falle gezogen', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Door', 'ColorActive' => true, 'ColorValue' => 0x00AAFF, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 8, 'IntervalMaxValue' => 9, 'ConstantActive' => true, 'ConstantValue' => 'Falle zieht...', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Door', 'ColorActive' => true, 'ColorValue' => 0x0066CC, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 9, 'IntervalMaxValue' => 18, 'ConstantActive' => true, 'ConstantValue' => 'Unbekannt', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Information', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 18, 'IntervalMaxValue' => 19, 'ConstantActive' => true, 'ConstantValue' => 'Aktualisiert...', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Gear', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ]
        ]);

        $this->RegisterVariableInteger('LockState', 'Schloss Status', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $stateIntervals
        ], 1);
        
        $this->RegisterVariableInteger('BatteryLevel', 'Batterie', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Battery',
            'SUFFIX' => ' %'
        ], 2);
        
        $this->RegisterVariableBoolean('IsCharging', 'Wird geladen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Plug'
        ], 3);
        
        // Control variable
        $controlPres = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'Gear',
            'OPTIONS'      => json_encode([
                ['Value' => 0, 'Caption' => 'Entriegeln', 'IconActive' => true, 'IconValue' => 'LockOpen', 'Color' => -1],
                ['Value' => 1, 'Caption' => 'Verriegeln', 'IconActive' => true, 'IconValue' => 'LockClosed', 'Color' => -1],
                ['Value' => 2, 'Caption' => 'Falle ziehen', 'IconActive' => true, 'IconValue' => 'Door', 'Color' => -1],
                ['Value' => 3, 'Caption' => 'Entriegeln & Falle ziehen', 'IconActive' => true, 'IconValue' => 'LockOpen', 'Color' => -1]
            ])
        ];
        $this->RegisterVariableInteger('LockControl', 'Steuerung', $controlPres, 0);
        $this->EnableAction('LockControl');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->RegisterVariables();
        if (empty($this->ReadPropertyString('BridgeIP'))) {
            $this->SetTimerInterval('StatusUpdateTimer', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('StatusUpdateTimer', 900000);
        // --- Auto-generated References ---
        $ref_LockID = $this->ReadPropertyInteger('LockID');
        if ($ref_LockID > 1 && @IPS_ObjectExists($ref_LockID)) {
            $this->RegisterReference($ref_LockID);
        }
        // Migration: Delete legacy profiles
        if (IPS_VariableProfileExists('Tedee.LockState')) {
            IPS_SetVariableCustomProfile($this->GetIDForIdent('LockState'), '');
            IPS_DeleteVariableProfile('Tedee.LockState');
        }
        if (IPS_VariableProfileExists('Tedee.LockControl')) {
            IPS_SetVariableCustomProfile($this->GetIDForIdent('LockControl'), '');
            IPS_DeleteVariableProfile('Tedee.LockControl');
        }
        if (IPS_VariableProfileExists('Tedee.Battery')) {
            IPS_SetVariableCustomProfile($this->GetIDForIdent('BatteryLevel'), '');
            IPS_DeleteVariableProfile('Tedee.Battery');
        }

        // To make sure any previously incorrectly set custom presentation for battery is cleared:
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('BatteryLevel'), []);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('BatteryLevel'), '');

        $chargingOptions = json_encode([
            ['Value' => false, 'Caption' => 'Nein', 'IconValue' => 'Plug', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
            ['Value' => true, 'Caption' => 'Lädt', 'IconValue' => 'Plug', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00]
        ]);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('IsCharging'), '');
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('IsCharging'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Plug',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $chargingOptions
        ]);// Register Webhook Endpoint in Symcon
        $this->RegisterHook("Tedee_" . $this->InstanceID);

        // Fetch initial status once upon apply
        $this->UpdateStatus();

        // Auto-Register Webhook at Bridge if URL is provided
        $baseUrl = $this->ReadPropertyString('SymconBaseURL');
        if (!empty($baseUrl)) {
            $this->RegisterWebhookAtBridge();
        }

    }



    protected function RegisterHook(string $HookPath): bool
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
        if (sizeof($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            if (!is_array($hooks)) $hooks = [];
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $HookPath) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return true;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $HookPath, "TargetID" => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
            return true;
        }
        return false;
    }

    protected function ProcessHookData(): void
    {
        $payload = file_get_contents('php://input');
        $this->SendDebug('Webhook', 'Empfange Webhook: ' . $payload, 0);

        if (empty($payload)) return;
        $this->DA_ResetWatchdog(3600);
        $this->DA_SetAvailable(true);

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['event'])) return;

        $targetLockId = $this->GetActiveLockID();

        $data = $event['data'] ?? [];
        if (!isset($data['deviceId'])) return;

        $lockId = (int)$data['deviceId'];
        
        // Only process if it matches the configured LockID (or if 0, update the attribute and use it)
        if ($targetLockId !== 0 && $lockId !== $targetLockId) {
            return;
        }

        if ($targetLockId === 0) {
            $this->WriteAttributeInteger('DetectedLockID', $lockId);
        }

        // Handle lock state changes
        if ($event['event'] === 'lock-status-changed') {
            if (isset($data['state'])) {
                $this->SetValue('LockState', (int)$data['state']);
                
                $controlValue = -1;
                if ($data['state'] == 2) {
                    $controlValue = 0;
                } elseif ($data['state'] == 6) {
                    $controlValue = 1;
                }
                if ($controlValue !== -1 && GetValue($this->GetIDForIdent('LockControl')) != $controlValue) {
                    $this->SetValue('LockControl', (int)$controlValue);
                }
            }
        }
        
        // Handle battery events (bridge might send them under a different event name, catching fallback)
        if (isset($data['batteryLevel'])) {
            $this->SetValue('BatteryLevel', (int)$data['batteryLevel']);
        }
        if (isset($data['isCharging'])) {
            $this->SetValue('IsCharging', (bool)$data['isCharging']);
        }
    }

    public function RegisterWebhookAtBridge(): void
    {
        $ip = @$this->ReadPropertyString('BridgeIP');
        $token = @$this->ReadPropertyString('ApiToken');
        $baseUrl = rtrim((string)@$this->ReadPropertyString('SymconBaseURL'), "/");
        $webhookUrl = $baseUrl . "/hook/Tedee_" . $this->InstanceID;

        if (empty($ip) || empty($token) || empty($baseUrl)) {
            $this->SendDebug('Webhook', 'Fehlende Daten für Webhook-Registrierung', 0);
            return;
        }

        // --- STEP 1: GET ALL CALLBACKS ---
        $apiToken = $token;
        if (@$this->ReadPropertyBoolean('UseEncryptedToken')) {
            $timestamp = (string)round(microtime(true) * 1000);
            $hash = hash('sha256', $token . $timestamp);
            $apiToken = $hash . $timestamp;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "api_token: $apiToken\r\nAccept: application/json\r\n",
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents("http://{$ip}/v1.0/callback", false, $context);
        $this->SendDebug('Webhook-List', "Existing: " . $response, 0);

        $callbacks = json_decode($response, true);
        if (is_array($callbacks)) {
            foreach ($callbacks as $cb) {
                // Delete ONLY old webhooks for THIS specific instance
                if (isset($cb['id']) && isset($cb['url']) && strpos($cb['url'], '/hook/Tedee_' . $this->InstanceID) !== false) {
                    sleep(1);
                    
                    $delToken = $token;
                    if (@$this->ReadPropertyBoolean('UseEncryptedToken')) {
                        $timestamp = (string)round(microtime(true) * 1000);
                        $hash = hash('sha256', $token . $timestamp);
                        $delToken = $hash . $timestamp;
                    }

                    $delOpts = [
                        'http' => [
                            'method' => 'DELETE',
                            'header' => "api_token: $delToken\r\n",
                            'timeout' => 5,
                            'ignore_errors' => true
                        ]
                    ];
                    $delContext = stream_context_create($delOpts);
                    $delResponse = @file_get_contents("http://{$ip}/v1.0/callback/" . $cb['id'], false, $delContext);
                    $this->SendDebug('Webhook-Delete', "Deleted ID " . $cb['id'] . ": " . $delResponse, 0);
                }
            }
        }

        // --- STEP 3: REGISTER NEW CALLBACK ---
        sleep(1);
        
        $regToken = $token;
        if (@$this->ReadPropertyBoolean('UseEncryptedToken')) {
            $timestamp = (string)round(microtime(true) * 1000);
            $hash = hash('sha256', $token . $timestamp);
            $regToken = $hash . $timestamp;
        }

        $payload = json_encode([
            "url" => $webhookUrl,
            "method" => "POST",
            "headers" => new stdClass()
        ]);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "api_token: $regToken\r\n" .
                            "Content-Type: application/json\r\n" .
                            "Content-Length: " . strlen($payload) . "\r\n",
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ];
        
        $context = stream_context_create($opts);
        $response = @file_get_contents("http://{$ip}/v1.0/callback", false, $context);
        
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            if (preg_match('/HTTP\/[\d\.]+ (\d+)/', $http_response_header[0], $matches)) {
                $httpCode = (int)$matches[1];
            }
        }

        $this->SendDebug('Webhook', "Registrierung an Bridge HTTP: $httpCode | Resp: $response", 0);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'DA_Watchdog') {
            $this->DA_HandleWatchdog();
            return;
        }
        if ($Ident === 'LockControl') {
            if ($Value == 0) {
                $this->SendCommand('unlock?mode=3');
            } elseif ($Value == 1) {
                $this->SendCommand('lock');
            } elseif ($Value == 2) {
                $this->SendCommand('pull');
            } elseif ($Value == 3) {
                $this->SendCommand('unlock');
            }
        }
    }

    private function GetActiveLockID(): int
    {
        $configId = @$this->ReadPropertyInteger('LockID');
        if ($configId > 0) {
            return $configId;
        }
        return $this->ReadAttributeInteger('DetectedLockID');
    }

    public function UpdateStatus(): void
    {
        $ip = @$this->ReadPropertyString('BridgeIP');
        $token = @$this->ReadPropertyString('ApiToken');
        
        if (empty($ip) || empty($token)) return;

        $data = $this->HttpRequest("http://{$ip}/v1.0/lock", 'GET', $this->GetAuthHeaders());
        if ($data === null) {
            $this->DA_SetAvailable(false, 'REST API nicht erreichbar');
            return;
        }

        if (is_array($data)) {
            $targetLockId = @$this->ReadPropertyInteger('LockID');
            $found = false;
            
            foreach ($data as $lock) {
                $lockId = (int)($lock['id'] ?? 0);
                
                // Match specific lock if configured, otherwise use first
                if ($targetLockId > 0 && $lockId !== $targetLockId) {
                    continue;
                }

                $found = true;
                if ($targetLockId === 0) {
                    $this->WriteAttributeInteger('DetectedLockID', $lockId);
                }
                
                if (isset($lock['state'])) {
                    $this->SetValue('LockState', (int)$lock['state']);
                    
                    // Map state to control variable to keep UI in sync
                    $controlValue = -1;
                    if ($lock['state'] == 2) { // Unlocked
                        $controlValue = 0;
                    } elseif ($lock['state'] == 6) { // Locked
                        $controlValue = 1;
                    }
                    if ($controlValue !== -1 && GetValue($this->GetIDForIdent('LockControl')) != $controlValue) {
                        $this->SetValue('LockControl', (int)$controlValue);
                    }
                }
                if (isset($lock['batteryLevel'])) {
                    $this->SetValue('BatteryLevel', (int)$lock['batteryLevel']);
                }
                if (isset($lock['isCharging'])) {
                    $this->SetValue('IsCharging', (bool)$lock['isCharging']);
                }
                break;
            }
            
            if ($found) {
                $this->SetStatus(102);
                $this->DA_SetAvailable(true);
                $this->DA_ResetWatchdog(3600);
            } else {
                $this->SetStatus(201); // Error state
                $this->SLogError("Schloss mit ID $targetLockId wurde von der Bridge nicht gemeldet.");
                $this->DA_SetAvailable(false, 'Schloss auf Bridge nicht gefunden');
            }
        }
    }

    private function SendCommand(string $action): void
    {
        $ip = $this->ReadPropertyString('BridgeIP');
        $token = $this->ReadPropertyString('ApiToken');
        $lockId = $this->GetActiveLockID();
        
        if (empty($ip) || empty($token) || $lockId === 0) {
            $this->SendDebug('SendCommand', 'Missing IP, Token or LockID', 0);
            return;
        }

        $headers = $this->GetAuthHeaders();
        $headers[] = 'Content-Length: 0';

        $res = $this->HttpRequest("http://{$ip}/v1.0/lock/{$lockId}/{$action}", 'POST', $headers);
        if ($res === null) {
            $this->DA_SetAvailable(false, 'REST Fehler');
        } else {
            $this->DA_SetAvailable(true);
        }
        $this->SendDebug('SendCommand', "Action: $action", 0);
    }

    private function GetAuthHeaders(): array
    {
        $token = $this->ReadPropertyString('ApiToken');
        if ($this->ReadPropertyBoolean('UseEncryptedToken')) {
            $timestamp = (string)round(microtime(true) * 1000);
            $hash = hash('sha256', $token . $timestamp);
            $apiToken = $hash . $timestamp;
        } else {
            $apiToken = $token;
        }

        return [
            'api_token: ' . $apiToken,
            'accept: application/json'
        ];
    }

    

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Willkommen bei deiner Tedee-Bridge Einrichtung! Hier stellst du die grundlegenden Verbindungsparameter ein."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "BridgeIP",
                    "caption": "Bridge IP-Adresse (z.B. 192.168.1.50)"
                },
                {
                    "type": "ValidationTextBox",
                    "name": "ApiToken",
                    "caption": "Local API Token"
                }
            ]
        },
        {
            "type": "Label",
            "label": "Sicherheit und Schloss-Auswahl: Nutze am besten verschlüsselte Tokens. Wenn du mehrere Schlösser hast, kannst du hier die ID deines Schlosses eintragen. Trägst du eine 0 ein, so wird automatisch das erste gefundene Schloss verwendet."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "CheckBox",
                    "name": "UseEncryptedToken",
                    "caption": "Verschlüsselter Token (Empfohlen, wie in der App eingestellt)"
                },
                {
                    "type": "NumberSpinner",
                    "name": "LockID",
                    "caption": "Lock ID (0 = automatisch das erste Schloss verwenden)",
                    "minimum": 0
                }
            ]
        },
        {
            "type": "Label",
            "label": "Webhook-Basis-URL: Über diese URL kommuniziert die Bridge mit deinem IP-Symcon, um Status-Updates in Echtzeit zu senden."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "SymconBaseURL",
                    "caption": "Symcon Base URL für Webhooks (z.B. http://10.1.60.150:3777)",
                    "validate": "^https?://.+"
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Status jetzt aktualisieren",
            "onClick": "TEDEE_UpdateStatus($id);"
        },
        {
            "type": "Button",
            "caption": "Webhook an Bridge registrieren",
            "onClick": "TEDEE_RegisterWebhookAtBridge($id);",
            "icon": "Play"
        }
    ],
    "status": [
        {"code": 102, "icon": "active",   "caption": "Aktiv"},
        {"code": 104, "icon": "inactive", "caption": "Nicht konfiguriert"},
        {"code": 200, "icon": "error",    "caption": "Verbindungsfehler"}
    ]
}
EOT;
    }
}


