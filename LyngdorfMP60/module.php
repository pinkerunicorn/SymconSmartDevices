<?php

declare(strict_types=1);

class LyngdorfMP60 extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeString('SourceMap', '[]');
        $this->RegisterAttributeString('AudioModeMap', '[]');
        $this->RegisterAttributeString('VoicingMap', '[]');


        $this->RegisterPropertyBoolean('HideVariablesWhenOff', false);

        // Receive Buffer für unvollständige TCP Pakete
        $this->SetBuffer('ReceiveBuffer', '');

        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', '⚡ Power', '', 1);
        IPS_SetIcon($this->GetIDForIdent('Power'), 'Power');
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('Volume', '🔊 Lautstärke', '', 2);
        IPS_SetIcon($this->GetIDForIdent('Volume'), 'Intensity');
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', '🔇 Mute', '', 3);
        IPS_SetIcon($this->GetIDForIdent('Mute'), 'Speaker');
        $this->EnableAction('Mute');

        $this->RegisterVariableInteger('Source', '🎵 Quelle', 'LYNG.Source', 4);
        IPS_SetIcon($this->GetIDForIdent('Source'), 'TV');
        $this->EnableAction('Source');

        $this->RegisterVariableInteger('AudioMode', '🎛 Audio Mode', 'LYNG.AudioMode', 5);
        IPS_SetIcon($this->GetIDForIdent('AudioMode'), 'Sound');
        $this->EnableAction('AudioMode');

        $this->RegisterVariableInteger('Voicing', '🗣 Voicing', '', 6);
        IPS_SetIcon($this->GetIDForIdent('Voicing'), 'Speaker');
        $this->EnableAction('Voicing');

        $this->RegisterVariableString('AudioTypeIn', '📥 Audio Type In', '', 7);
        IPS_SetIcon($this->GetIDForIdent('AudioTypeIn'), 'Information');
        $this->RegisterVariableString('AudioTypeOut', '📤 Audio Type Out', '', 8);
        IPS_SetIcon($this->GetIDForIdent('AudioTypeOut'), 'Information');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Self-Healing: Reset all corrupted presentations before re-applying
        foreach (['Power','Volume','Mute','Source','AudioMode','Voicing','AudioTypeIn','AudioTypeOut'] as $_ident) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent($_ident), []);
        }

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Power'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH,
            'ICON'        => 'Power'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Mute'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH,
            'ICON'        => 'Speaker'
        ]);


        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Volume'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SLIDER,
            'ICON'        => 'Intensity',
            'SUFFIX'      => 'dB',
            'MIN'         => -99.9,
            'MAX'         => 24.0,
            'STEP'        => 0.5
        ]);

                IPS_SetVariableCustomPresentation($this->GetIDForIdent('Source'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'TV'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('AudioMode'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Sound'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Voicing'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Speaker'
        ]);

        if ($this->HasActiveParent()) {
            $this->UpdateData();
        }

        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, 10505 /* IM_CHANGESTATUS */);
        }

        // Regelmäßiges Polling (alle 30 Sekunden) als Fallback
        $this->RegisterTimer('UpdatePolling', 30000, 'LYNG_UpdateData($_IPS[\'TARGET\']);');
    }

    protected function Log(string $text): void
    {
        $this->SLog('INFO', $text);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === 10505) { // IM_CHANGESTATUS
            if ($Data[0] === 102) { // IS_ACTIVE
                $this->SendDebug('System', 'Socket reconnected, forcing UpdateData', 0);
                $this->Log('Verbindung zum Gerät (wieder-)hergestellt. Lade Status...');
                $this->UpdateData();
            }
        }
    }

    public function RequestAction(string $Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->Log('Schalte Gerät EIN (POWERONMAIN)');
                    $this->SendCommand('!POWERONMAIN');
                } else {
                    $this->Log('Schalte Gerät AUS (POWEROFFMAIN)');
                    $this->SendCommand('!POWEROFFMAIN');
                }
                break;

            case 'Volume':
                $volInt = (int)round($Value * 10);
                $this->SendCommand('!VOL('. $volInt . ')');
                break;

            case 'Mute':
                if ($Value) {
                    $this->SendCommand('!MUTEON');
                } else {
                    $this->SendCommand('!MUTEOFF');
                }
                break;

            case 'Source':
                $this->SendCommand('!SRC('. $Value . ')');
                break;

            case 'AudioMode':
                $this->SendCommand('!AUDMODE('. $Value . ')');
                break;

            case 'Voicing':
                $this->SendCommand('!RPVOI('. $Value . ')');
                break;
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SLog('ERROR', 'Ungültiges JSON empfangen', json_last_error_msg());
            return '';
        }
        if (isset($data->Buffer)) {
            $payload = is_string($data->Buffer) ? hex2bin($data->Buffer) : '';
        } else {
            return "";
        }
        
        $buffer = $this->GetBuffer('ReceiveBuffer');
        $buffer .= $payload;

        $packets = explode("\r", $buffer);
        $this->SetBuffer('ReceiveBuffer', array_pop($packets));

        foreach ($packets as $packet) {
            $packet = trim($packet);
            if (!empty($packet)) {
                $this->ProcessPacket($packet);
            }
        }
        return "";
    }

    public function UpdateData(): void
    {
                IPS_SetVariableCustomPresentation($this->GetIDForIdent('Source'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'TV'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('AudioMode'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Sound'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Voicing'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Speaker'
        ]);

        if ($this->HasActiveParent()) {
            $this->SendCommand('!VERB(1)');
            $this->SendCommand('!POWER?');
            $this->SendCommand('!VOL?');
            $this->SendCommand('!MUTE?');
            $this->SendCommand('!SRCS?');
            $this->SendCommand('!AUDMODEL?');
            $this->SendCommand('!RPVOIS?');
            $this->SendCommand('!SRC?');
            $this->SendCommand('!AUDMODE?');
            $this->SendCommand('!RPVOI?');
            $this->SendCommand('!AUDTYPE?');
            $this->SendCommand('!AUDTYPEOUT?');
        }
    }

    private function ProcessPacket(string $packet): void
    {
        if (strpos($packet, '!') !== 0 && strpos($packet, '#') !== 0) {
            return;
        }

        $command = substr($packet, 1);

        $this->SendDebug('Receive', $command, 0);

        if (preg_match('/^POWER\((\d)\)$/', $command, $matches)) {
            $power = ($matches[1] == '1');
            if ($this->GetValue('Power') !== $power) {
                $this->Log('Status geändert: Power = '. ($power ? 'ON': 'OFF'));
            }
            $this->SetValue('Power', (bool)$power);
            $this->UpdateVisibility($power);
        } 
        elseif ($command === 'POWERONMAIN'|| $command === 'PON'|| $command === 'POWERON') {
            if (!$this->GetValue('Power')) {
                $this->Log('Status geändert: Power = ON');
            }
            $this->SetValue('Power', true);
            $this->UpdateVisibility(true);
        }
        elseif ($command === 'POWEROFFMAIN'|| $command === 'POFF'|| $command === 'POWEROFF') {
            if ($this->GetValue('Power')) {
                $this->Log('Status geändert: Power = OFF');
            }
            $this->SetValue('Power', false);
            $this->UpdateVisibility(false);
        }
        elseif (preg_match('/^VOL\((-?\d+)\)$/', $command, $matches)) {
            $this->SetValue('Volume', floatval($matches[1]) / 10);
        }
        elseif ($command === 'MUTEON') {
            $this->SetValue('Mute', true);
        }
        elseif ($command === 'MUTEOFF') {
            $this->SetValue('Mute', false);
        }
        elseif (preg_match('/^SRC\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateDynamicProfile('Source', 'SourceMap', $index, $name, 'TV');
            $this->SetValue('Source', $index);
        }
        elseif (preg_match('/^SRC\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Source', intval($matches[1]));
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateDynamicProfile('AudioMode', 'AudioModeMap', $index, $name, 'Sound');
            $this->SetValue('AudioMode', $index);
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('AudioMode', intval($matches[1]));
        }
        elseif (preg_match('/^RPVOI\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateDynamicProfile('Voicing', 'VoicingMap', $index, $name, 'Speaker');
            $this->SetValue('Voicing', $index);
        }
        elseif (preg_match('/^RPVOI\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Voicing', intval($matches[1]));
        }
        elseif (preg_match('/^AUDTYPE\((.*)\)$/', $command, $matches)) {
            $this->SetValue('AudioTypeIn', $matches[1]);
        }
        elseif (preg_match('/^AUDTYPEOUT\((.*)\)$/', $command, $matches)) {
            $this->SetValue('AudioTypeOut', $matches[1]);
        }
    }

    private function SendCommand(string $command): void
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        $this->SendDebug('Transmit', $command, 0);
        
        $this->SendDataToParent(json_encode([
            'DataID'=> '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
            'Buffer'=> bin2hex($command . "\r")
        ]));
    }

    private function UpdateVisibility(bool $powerState): void
    {
        if (!$this->ReadPropertyBoolean('HideVariablesWhenOff')) {
            $this->SetHiddenSafe('Volume', false);
            $this->SetHiddenSafe('Mute', false);
            $this->SetHiddenSafe('Source', false);
            $this->SetHiddenSafe('AudioMode', false);
            $this->SetHiddenSafe('Voicing', false);
            $this->SetHiddenSafe('AudioTypeIn', false);
            $this->SetHiddenSafe('AudioTypeOut', false);
            return;
        }

        $hidden = !$powerState;
        $this->SetHiddenSafe('Volume', $hidden);
        $this->SetHiddenSafe('Mute', $hidden);
        $this->SetHiddenSafe('Source', $hidden);
        $this->SetHiddenSafe('AudioMode', $hidden);
        $this->SetHiddenSafe('Voicing', $hidden);
        $this->SetHiddenSafe('AudioTypeIn', $hidden);
        $this->SetHiddenSafe('AudioTypeOut', $hidden);
    }

    private function SetHiddenSafe(string $ident, bool $hidden): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && $id > 0) {
            IPS_SetHidden($id, $hidden);
        }
    }



    private function SLog(string $level, string $message, string $details = ''): void
    {
        $source = static::class;
        $slogInstances = @IPS_GetInstanceListByModuleID('{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}');
        if (is_array($slogInstances) && count($slogInstances) > 0) {
            @SLOG_Log($slogInstances[0], $level, $source, $message, $details);
        } else {
            IPS_LogMessage('SmartVillaKunterbunt', $source . ': ' . $message);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'LyngdorfMP60: '. $Message);
        return true;
    }

    private function UpdateDynamicProfile(string $ident, string $mapName, int $index, string $name, string $icon): void
    {
        $map = json_decode($this->ReadAttributeString($mapName), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SLog('ERROR', 'Ungültiges JSON empfangen', json_last_error_msg());
            return;
        }
        if (!is_array($map)) {
            $map = [];
        }
        $map[$index] = $name;
        $this->WriteAttributeString($mapName, json_encode($map));
        
        $profileName = 'Lyngdorf.'. $ident . '.'. $this->InstanceID;
        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, 1);
        }
        IPS_SetVariableProfileAssociation($profileName, $index, $name, $icon, -1);
        IPS_SetVariableCustomProfile($this->GetIDForIdent($ident), $profileName);
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Hier stellst du ein, ob die Variablen im WebFront versteckt werden sollen, wenn der Receiver ausgeschaltet ist. Das sorgt für mehr Übersichtlichkeit!"
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "CheckBox",
                    "name": "HideVariablesWhenOff",
                    "caption": "Variablen verstecken, wenn das Gerät ausgeschaltet ist"
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Label",
            "caption": "Hier kannst du die aktuellen Werte manuell vom Receiver abfragen, falls mal etwas asynchron sein sollte."
        },
        {
            "type": "Button",
            "label": "Werte manuell vom Receiver aktualisieren",
            "onClick": "LYNG_UpdateData($id);"
        }
    ],
    "status": []
}
EOT;
    }
}

