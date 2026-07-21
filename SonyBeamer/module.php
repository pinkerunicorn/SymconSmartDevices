<?php

declare(strict_types=1);

class SonyBeamer extends IPSModuleStrict
{

    private static array $inputMap = [
        0 => 'hdmi1',
        1 => 'hdmi2',
        2 => 'video1',
        3 => 'component'
    ];

    private static array $pictureModeMap = [
        0 => 'dynamic',
        1 => 'standard',
        2 => 'brt_priority',
        3 => 'cinema_film_1',
        4 => 'cinema_film_2',
        5 => 'reference',
        6 => 'tv',
        7 => 'photo',
        8 => 'game',
        9 => 'bright_cinema',
        10 => 'bright_tv',
        11 => 'user'
    ];

    public function Create(): void{
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyString('Host', '192.168.1.100');
        $this->RegisterPropertyInteger('Port', 53595);
        $this->RegisterPropertyInteger('UpdateInterval', 20); // Default to 20s

        // Timer fr Polling
        $this->RegisterTimer('UpdateTimer', 0, 'SONY_UpdateStatus($_IPS[\'TARGET\']);');

        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', '📺 Status', '', 10);
        IPS_SetIcon($this->GetIDForIdent('Power'), 'Power');
        $this->EnableAction('Power');

        // Alte String-Variablen entfernen, falls vorhanden
        $inputId = @$this->GetIDForIdent('Input');
        if ($inputId && IPS_GetVariable($inputId)['VariableType'] !== 1) { // 1 = Integer
            $this->UnregisterVariable('Input');
        }
        $picId = @$this->GetIDForIdent('PictureMode');
        if ($picId && IPS_GetVariable($picId)['VariableType'] !== 1) {
            $this->UnregisterVariable('PictureMode');
        }

        $this->RegisterVariableInteger('Input', '🔌 Eingang', 'Sony.Input', 20);
        IPS_SetIcon($this->GetIDForIdent('Input'), 'Plug');
        $this->EnableAction('Input');

        $this->RegisterVariableInteger('PictureMode', '🖼 Bildmodus', 'Sony.PictureMode', 30);
        IPS_SetIcon($this->GetIDForIdent('PictureMode'), 'TV');
        $this->EnableAction('PictureMode');

        $this->RegisterVariableInteger('OperationTime', '⏱ Betriebsstunden', '', 40);
        IPS_SetIcon($this->GetIDForIdent('OperationTime'), 'Clock');
        $this->RegisterVariableInteger('LightSourceTime', '💡 Lampenstunden', '', 50);
        IPS_SetIcon($this->GetIDForIdent('LightSourceTime'), 'Bulb');
        $this->RegisterVariableString('Warning', 'Warnungen', '', 60);
        IPS_SetIcon($this->GetIDForIdent('Warning'), 'Warning');
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval < 5) $interval = 5;
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

        // Self-Healing: Reset all corrupted presentations before re-applying
        foreach (['Power','Input','PictureMode','OperationTime','LightSourceTime','Warning'] as $_ident) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent($_ident), []);
        }

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Power'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH,
            'ICON'        => 'Power'
        ]);
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('OperationTime'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Clock',
            'SUFFIX'=> 'h'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('LightSourceTime'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Bulb',
            'SUFFIX'=> 'h'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Warning'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Warning'
        ]);
        
        // Input Profil
        if (IPS_VariableProfileExists('Sony.Input') && IPS_GetVariableProfile('Sony.Input')['ProfileType'] !== 1) {
            IPS_DeleteVariableProfile('Sony.Input');
        }
        if (!IPS_VariableProfileExists('Sony.Input')) {
            IPS_CreateVariableProfile('Sony.Input', 1); // 1 = Integer
            IPS_SetVariableProfileAssociation('Sony.Input', 0, 'HDMI 1', '', -1);
            IPS_SetVariableProfileAssociation('Sony.Input', 1, 'HDMI 2', '', -1);
            IPS_SetVariableProfileAssociation('Sony.Input', 2, 'Video 1', '', -1);
            IPS_SetVariableProfileAssociation('Sony.Input', 3, 'Component', '', -1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('Input'), 'Sony.Input');
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Input'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'Plug'
        ]);
        
        // Picture Mode Profil
        if (IPS_VariableProfileExists('Sony.PictureMode') && IPS_GetVariableProfile('Sony.PictureMode')['ProfileType'] !== 1) {
            IPS_DeleteVariableProfile('Sony.PictureMode');
        }
        if (!IPS_VariableProfileExists('Sony.PictureMode')) {
            IPS_CreateVariableProfile('Sony.PictureMode', 1); // 1 = Integer
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 0, 'Dynamic', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 1, 'Standard', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 2, 'Brightness Priority', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 3, 'Cinema Film 1', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 4, 'Cinema Film 2', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 5, 'Reference', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 6, 'TV', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 7, 'Photo', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 8, 'Game', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 9, 'Bright Cinema', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 10, 'Bright TV', '', -1);
            IPS_SetVariableProfileAssociation('Sony.PictureMode', 11, 'User', '', -1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('PictureMode'), 'Sony.PictureMode');
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('PictureMode'), [
                'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'TV'
        ]);

        $this->UpdateVisibility(!$this->GetValue('Power'));
    }

    protected function Log(string $Message): void
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SonyBeamer: '. $Message);
    }

    public function RequestAction(string $Ident, $Value): void{
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->SendSingleCommand('power "on"');
                    $this->Log("Einschaltbefehl gesendet.");
                } else {
                    $this->SendSingleCommand('power "off"');
                    $this->Log("Ausschaltbefehl gesendet.");
                }
                break;
            case 'Input':
                if (isset(self::$inputMap[$Value])) {
                    $cmdVal = self::$inputMap[$Value];
                    $this->SendSingleCommand("input \"$cmdVal\"");
                    $this->Log("Eingang auf $cmdVal gesetzt.");
                }
                break;
            case 'PictureMode':
                if (isset(self::$pictureModeMap[$Value])) {
                    $cmdVal = self::$pictureModeMap[$Value];
                    $this->SendSingleCommand("picture_mode \"$cmdVal\"");
                    $this->Log("Bildmodus auf $cmdVal gesetzt.");
                }
                break;
            default:
                throw new Exception("Invalid Action");
        }
        
        IPS_Sleep(500);
        $this->UpdateStatus();
    }

    public function UpdateStatus(): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');

        if (empty($host)) {
            $this->SendDebug("Log", "UpdateStatus abgebrochen: Keine IP-Adresse (Host) konfiguriert!", 0);
            return;
        }

        $this->SendDebug("Log", "Verbinde mit Beamer $host:$port...", 0);
        
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) {
            $this->SendDebug("Log", "Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            return;
        }
        
        stream_set_timeout($fp, 2);
        
        // Begrüßung abwarten
        $greeting = fread($fp, 128);
        if (!empty(trim((string)$greeting))) {
            $this->SendDebug("Log", "Begrüßung: ". trim((string)$greeting), 0);
        }
        
        $commands = [
            'power_status ?',
            'input ?',
            'picture_mode ?',
            'error ?',
            'timer ?'
        ];
        
        foreach ($commands as $cmd) {
            $this->SendDebug("Transmit", $cmd, 0);
            fwrite($fp, $cmd . "\r\n");
            
            // Warte auf Antwort
            $response = fread($fp, 1024);
            $response = trim((string)$response);
            if (!empty($response)) {
                $this->SendDebug("Receive", $response, 0);
                
                $lines = explode("\n", str_replace("\r", "", $response));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $this->ParseLine($line);
                    }
                }
            } else {
                $this->SendDebug("Log", "Keine Antwort auf $cmd", 0);
            }
            
            IPS_Sleep(200); // Kurze Pause zwischen den Befehlen
        }
        
        fclose($fp);
    }

    private function SendSingleCommand(string $cmd): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');

        if (empty($host)) return;

        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) {
            $this->SendDebug("Log", "Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            return;
        }
        
        stream_set_timeout($fp, 2);
        fread($fp, 128); // Begrüßung ignorieren
        
        $this->SendDebug("Transmit", $cmd, 0);
        fwrite($fp, $cmd . "\r\n");
        
        $response = trim((string)fread($fp, 1024));
        if (!empty($response)) {
            $this->SendDebug("Receive", $response, 0);
            $lines = explode("\n", str_replace("\r", "", $response));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $this->ParseLine($line);
                }
            }
        }
        fclose($fp);
    }

    private function ParseLine(string $line): void
    {
        $cleanLine = trim($line, '"');
        
        if ($cleanLine === 'ok') {
            return;
        }

        if (in_array($cleanLine, ['err_cmd', 'err_inactive'])) {
            $this->SendDebug("ParseError", "Beamer meldet Fehler / Ablehnung: ". $cleanLine . "(Mögliche Ursache: Beamer ist im Standby oder Befehl ungültig)", 0);
            return;
        }
        
        if ($cleanLine === 'NOKEY') {
            $this->SendDebug("Log", "Beamer sendet NOKEY (Authentifizierung ist aus, das ist normal!).", 0);
            return;
        }

        // Power Status
        if (in_array($cleanLine, ['standby', 'startup', 'on', 'cooling1', 'cooling2', 'saving_standby'])) {
            $isPowered = ($cleanLine === 'on'|| $cleanLine === 'startup');
            if ($this->GetValue('Power') !== $isPowered) {
                $this->SetValue('Power', (bool)$isPowered);
                $this->UpdateVisibility(!$isPowered);
            }
            return;
        }

        // Inputs
        $inputKey = array_search($cleanLine, self::$inputMap);
        if ($inputKey !== false) {
             if ($this->GetValue('Input') !== $inputKey) {
                 $this->SetValue('Input', $inputKey);
             }
             return;
        }

        // Picture Mode
        $picKey = array_search($cleanLine, self::$pictureModeMap);
        if ($picKey !== false) {
             if ($this->GetValue('PictureMode') !== $picKey) {
                 $this->SetValue('PictureMode', $picKey);
             }
             return;
        }

        // Timer (JSON Array)
        if (strpos($line, '[') === 0 && strpos($line, '{') !== false) {
             $arr = json_decode($line, true);
             if (is_array($arr)) {
                 foreach ($arr as $item) {
                     if (isset($item['operation'])) {
                         $this->SetValue('OperationTime', (int)$item['operation']);
                     }
                     if (isset($item['light_src'])) {
                         $this->SetValue('LightSourceTime', (int)$item['light_src']);
                     }
                 }
             }
             return;
        }

        // Error / Warning (JSON Array aus Strings)
        if (strpos($line, '[') === 0 && strpos($line, '{') === false) {
             $arr = json_decode($line, true);
             if (is_array($arr) && count($arr) > 0) {
                 $this->SetValue('Warning', $arr[0]);
             }
             return;
        }
    }

    private function UpdateVisibility(bool $hidden): void
    {
        $idents = ['Input', 'PictureMode', 'OperationTime', 'LightSourceTime', 'Warning'];
        foreach ($idents as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false && $id > 0) {
                IPS_SetHidden($id, $hidden);
            }
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SonyBeamer: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Hier stellst du die Netzwerkverbindung zu deinem Sony Beamer ein. Trage die IP-Adresse und den Port deines Geräts ein."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "Host",
                    "caption": "IP-Adresse"
                },
                {
                    "type": "NumberSpinner",
                    "name": "Port",
                    "caption": "Port"
                },
                {
                    "type": "NumberSpinner",
                    "name": "UpdateInterval",
                    "caption": "Update Intervall (Sekunden)"
                }
            ]
        },
        {
            "type": "Label",
            "label": "Tipp: Das Update Intervall bestimmt, wie oft die Daten vom Beamer abgefragt werden. Setze es nicht zu niedrig, um das Netzwerk nicht zu belasten (Empfehlung: 20 Sekunden)."
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Status jetzt aktualisieren",
            "onClick": "SONY_UpdateStatus($id);"
        }
    ]
}
EOT;
    }
}
