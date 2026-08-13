<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class PixelblazeController extends IPSModuleStrict
{
    use SmartLog_Trait;

    use DeviceAvailability_Trait;


    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterWatchdog();
        $this->DA_RegisterAvailability(900); // Alarm priority: 0 (Low - decorative LEDs)

        // Properties
        $this->RegisterPropertyInteger('AutoReconnectInterval', 30);

        // Internes Attribut für die letzte Helligkeit vor dem Ausschalten
        $this->RegisterAttributeInteger('LastBrightness', 50);
        // Internes Attribut für die Programmliste (Map von Index -> String ID)
        $this->RegisterAttributeString('ProgramMap', '[]');

        // Variablen
        $this->RegisterVariableBoolean('Power', 'Status', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Power'
        ], 10);
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('Brightness', 'Helligkeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Sun',
            'MIN' => 0.0,
            'MAX' => 100.0,
            'STEP' => 1.0,
            'SUFFIX' => ' %'
        ], 20);
        $this->EnableAction('Brightness');
            
        $this->RegisterVariableInteger('ActiveProgram', 'Programm', ['ICON' => 'Script'], 30);
        $this->EnableAction('ActiveProgram');

        $this->RegisterVariableString('ActiveProgramName', 'Aktuelles Programm (Name)', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 35);

        // Timer für Auto-Reconnect
        $this->RegisterTimer('ReconnectTimer', 0, 'PB_Reconnect($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'require',
            'modules' => [
                [
                    'moduleID' => '{D68FD31F-0E90-7019-F16C-1949BD3079EF}'
                ]
            ]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // Alte String-Variable löschen falls vorhanden
        $oldVar = @$this->GetIDForIdent('ActiveProgramID');
        if ($oldVar > 0) {
            $this->UnregisterVariable('ActiveProgramID');
        }



        $interval = $this->ReadPropertyInteger('AutoReconnectInterval');
        $this->SetTimerInterval('ReconnectTimer', $interval * 1000);

        // Alten Timer deaktivieren falls vorhanden
        @$this->SetTimerInterval('FetchStateTimer', 0);



        $mapRaw = $this->ReadAttributeString('ProgramMap');
        $map = json_decode($mapRaw, true);
        
        if (is_array($map)) {
            $options = [];
            foreach ($map as $i => $prog) {
                $options[] = [
                    'Value' => $i,
                    'Caption' => $prog['name'],
                    'IconActive' => false,
                    'IconValue' => '',
                    'Color' => -1
                ];
            }
            $this->RegisterVariableInteger('ActiveProgram', 'Programm', [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'ICON' => 'Script',
                'OPTIONS' => json_encode($options)
            ], 30);
            IPS_SetVariableCustomProfile($this->GetIDForIdent('ActiveProgram'), '');
        }

        // Migration: Delete legacy profiles
        if (IPS_VariableProfileExists('Pixelblaze.Program')) {
            IPS_DeleteVariableProfile('Pixelblaze.Program');
        }
        $profileName = 'Pixelblaze.Program.' . $this->InstanceID;
        if (IPS_VariableProfileExists($profileName)) {
            IPS_DeleteVariableProfile($profileName);
        }
        
        $this->UpdateVisibility($this->GetValue('Power'));

    }

    private function UpdateVisibility(bool $isVisible): void
    {
        $hidden = !$isVisible;
        $this->SetHiddenSafe('Brightness', $hidden);
        $this->SetHiddenSafe('ActiveProgram', $hidden);
    }

    private function SetHiddenSafe(string $ident, bool $hidden): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            IPS_SetHidden($id, $hidden);
        }
    }

    

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                break;
            case 'Power':
                if ($Value) {
                    // Einschalten -> Letzte Helligkeit wiederherstellen
                    $brightness = $this->ReadAttributeInteger('LastBrightness');
                    if ($brightness <= 0) {
                        $brightness = 100;
                    }
                    $this->SetBrightness((float)$brightness);
                    $this->SetValue('Power', true);
                    $this->SetValue('Brightness', $brightness);
                    $this->UpdateVisibility(true);
                    $this->SLogInfo("Angeschaltet mit Helligkeit: " . $brightness . "%");
                } else {
                    // Ausschalten -> Aktuelle Helligkeit speichern, dann auf 0 setzen
                    $current = $this->GetValue('Brightness');
                    if ($current > 0) {
                        $this->WriteAttributeInteger('LastBrightness', $current);
                    }
                    $this->SetBrightness(0.0);
                    $this->SetValue('Power', false);
                    $this->SetValue('Brightness', 0);
                    $this->UpdateVisibility(false);
                    $this->SLogInfo("Ausgeschaltet. Letzte Helligkeit " . $current . "% gespeichert.");
                }
                break;

            case 'Brightness':
                $this->SetBrightness((float)$Value);
                $this->SetValue('Brightness', (int)$Value);
                
                if ($Value > 0) {
                    $this->SetValue('Power', true);
                    $this->UpdateVisibility(true);
                    $this->SLogInfo("Helligkeit auf " . $Value . "% gesetzt (Gerät AN).");
                } else {
                    $this->SetValue('Power', false);
                    $this->UpdateVisibility(false);
                    $this->SLogInfo("Helligkeit auf 0% gesetzt (Gerät AUS).");
                }
                break;

            case 'ActiveProgram':
                $mapRaw = $this->ReadAttributeString('ProgramMap');
                $map = json_decode($mapRaw, true);
                if (is_array($map) && isset($map[(int)$Value])) {
                    $progId = $map[(int)$Value]['id'];
                    $progName = $map[(int)$Value]['name'];
                    $this->SetActiveProgram($progId);
                    $this->SetValue('ActiveProgram', (int)$Value);
                    $this->SLogInfo("Programm gewechselt auf: " . $progName);
                } else {
                    $this->SLogInfo("Fehler: Programm-Index " . $Value . " nicht gefunden.");
                }
                break;

            default:
                throw new Exception("Invalid Action");
        }
    }

    public function FetchPrograms(): void
    {
        $this->SendJsonCommand(json_encode(['listPrograms' => true]));
    }

    public function FetchState(): void
    {
        $this->SendJsonCommand(json_encode(['getConfig' => true]));
    }

    public function Reconnect(): void
    {
        if (!$this->HasActiveParent()) {
            $parentID = $this->GetParentID();
            if ($parentID > 0) {
                // Nur reconnecten, wenn die Instanz grundstzlich "Open" oder "Active" geschaltet ist
                $parentConfig = json_decode(IPS_GetConfiguration($parentID), true);
                $propName = isset($parentConfig['Active']) ? 'Active' : (isset($parentConfig['Open']) ? 'Open' : '');
                if ($propName !== '' && IPS_GetProperty($parentID, $propName)) {
                    $this->SLogInfo("Verbindung getrennt. Versuche Reconnect...");
                    @IPS_SetProperty($parentID, $propName, false);
                    @IPS_ApplyChanges($parentID);
                    @IPS_SetProperty($parentID, $propName, true);
                    @IPS_ApplyChanges($parentID);
                }
            }
        }
    }

    private function GetParentID(): int
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        return ($instance && isset($instance['ConnectionID'])) ? $instance['ConnectionID'] : 0;
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug("RawReceiveData", $JSONString, 0);
        $data = json_decode($JSONString, true);
        $this->DA_ResetWatchdog(120);
        $this->DA_SetAvailable(true);
        if (!is_array($data)) return '';
        
        // WebSocket Client Data ID
        if ($data['DataID'] == '{018EF6B5-AB94-40C6-AA53-46943E824ACF}') {
            $bufferRaw = trim($data['Buffer']);
            // Ab IP-Symcon 6 wird der Buffer oft als HEX-String übergeben
            $buffer = (ctype_xdigit($bufferRaw)) ? hex2bin($bufferRaw) : $bufferRaw;

            // Prfe auf JSON Text-Frame (Status Updates etc.)
            if (strpos($buffer, '{') === 0) {
                // Teile nach Zeilenumbrchen auf, falls mehrere Pakete zusammengefasst wurden
                $lines = preg_split('/[\r\n]+/', $buffer);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;

                    // Pixelblaze sendet oft aneinandergereihte JSONs (z.B. {"fps":...}{"brightness":...})
                    $jsonChunks = str_replace('}{', '},{', $line);
                    $jsonArrayStr = '[' . $jsonChunks . ']';
                    $payloadArray = json_decode($jsonArrayStr, true);
                    
                    if (is_array($payloadArray)) {
                        foreach ($payloadArray as $payload) {
                            if (!is_array($payload)) continue;

                            // Helligkeit
                            if (isset($payload['brightness'])) {
                                $brightness = (int)round((float)$payload['brightness'] * 100.0);
                                if ($brightness != $this->GetValue('Brightness')) {
                                    $this->SetValue('Brightness', $brightness);
                                    $this->SetValue('Power', $brightness > 0);
                                    $this->UpdateVisibility($brightness > 0);
                                }
                            }
                            if (isset($payload['activeProgram']['activeProgramId'])) {
                                $progId = $payload['activeProgram']['activeProgramId'];
                                
                                // Speichere den echten Namen auch direkt in eine String-Variable zur Anzeige
                                if (isset($payload['activeProgram']['name'])) {
                                    $progName = $payload['activeProgram']['name'];
                                    if ($this->GetValue('ActiveProgramName') !== $progName) {
                                        $this->SetValue('ActiveProgramName', $progName);
                                    }
                                }

                                $mapRaw = $this->ReadAttributeString('ProgramMap');
                                $map = json_decode($mapRaw, true);
                                if (is_array($map)) {
                                    foreach ($map as $index => $progData) {
                                        if ($progData['id'] === $progId) {
                                            if ($index != $this->GetValue('ActiveProgram')) {
                                                $this->SetValue('ActiveProgram', $index);
                                            }
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $this->SendDebug("JSONError", "Decode failed: " . json_last_error_msg() . " for: " . $line, 0);
                    }
                }
                return "";
            }

            // Prfe auf binren listPrograms Frame (0x07)
            if (strlen($buffer) >= 2 && ord($buffer[0]) === 0x07) {
                $flags = ord($buffer[1]);
                $payload = substr($buffer, 2);

                if ($flags & 0x01) { // Start
                    $this->SetBuffer('ProgramListBuffer', '');
                }

                $currentBuffer = $this->GetBuffer('ProgramListBuffer');
                $currentBuffer .= $payload;
                $this->SetBuffer('ProgramListBuffer', $currentBuffer);

                if ($flags & 0x04) { // End
                    $this->ProcessProgramList($currentBuffer);
                    $this->SetBuffer('ProgramListBuffer', '');
                }
            }
        }
        
        return "";
    }

    private function ProcessProgramList($rawList): void
    {
        $lines = explode("\n", trim($rawList));
        $programs = [];
        $index = 0;
        foreach ($lines as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) >= 2) {
                $id = $parts[0];
                $name = $parts[1];
                if (!empty($id)) {
                    $programs[$index] = ['id' => $id, 'name' => $name];
                    $index++;
                }
            }
        }

                if (count($programs) > 0) {
            $this->WriteAttributeString('ProgramMap', json_encode($programs));

            $options = [];
            foreach ($programs as $i => $prog) {
                $options[] = [
                    'Value' => $i,
                    'Caption' => $prog['name'],
                    'IconActive' => false,
                    'IconValue' => '',
                    'Color' => -1
                ];
            }
            
            $this->RegisterVariableInteger('ActiveProgram', 'Programm', [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'ICON' => 'Script',
                'OPTIONS' => json_encode($options)
            ], 30);
            IPS_SetVariableCustomProfile($this->GetIDForIdent('ActiveProgram'), '');

            $this->SLogInfo(count($programs) . " Programme geladen und als Dropdown hinterlegt.");
        }
    }

    private function SetBrightness(float $percent): void
    {
        // Pixelblaze erwartet Float von 0.0 bis 1.0
        $floatValue = $percent / 100.0;
        if ($floatValue < 0.0) $floatValue = 0.0;
        if ($floatValue > 1.0) $floatValue = 1.0;

        $command = ['brightness' => $floatValue];
        $this->SendWebSocketCommand($command);
    }

    private function SetActiveProgram(string $programId): void
    {
        $command = ['activeProgramId' => $programId];
        $this->SendWebSocketCommand($command);
    }

    public function SendJsonCommand(string $jsonString): void
    {
        $data = json_decode($jsonString, true);
        if ($data) {
            $this->SendWebSocketCommand($data);
        } else {
            $this->SLogInfo("SendJsonCommand: Ungültiges JSON Format.");
        }
    }

    private function SendWebSocketCommand(array $payload): void
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug("SendWebSocketCommand", "Fehler: Kein aktiver WebSocket Client verbunden.", 0);
            return;
        }

        $jsonPayload = json_encode($payload);
        $parent = $this->GetParentID();
        
        if ($parent > 0) {
            // WSC_SendMessage sendet garantiert einen Text-Frame
            if (function_exists('WSC_SendMessage')) {
                WSC_SendMessage($parent, $jsonPayload);
            } else {
                // Fallback fr Client Socket (raw TCP)
                $msg = [
                    'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
                    'Buffer' => $jsonPayload
                ];
                $this->SendDataToParent(json_encode($msg));
            }
            $this->SendDebug("Transmit", $jsonPayload, 0);
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Hier stellst du ein, wie oft das Modul im Hintergrund arbeiten soll. Du kannst das Auto-Reconnect Intervall für Verbindungsabbrüche anpassen."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "AutoReconnectInterval",
                    "caption": "Auto-Reconnect Intervall (Sekunden)"
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Label",
            "label": "Hier kannst du die auf dem Pixelblaze gespeicherten Programme abrufen, damit du sie in der Oberfläche bequem auswählen kannst."
        },
        {
            "type": "Button",
            "label": "Programme vom Gerät laden",
            "onClick": "PB_FetchPrograms($id);"
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

