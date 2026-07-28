<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
class Michi extends IPSModuleStrict
{
    use SmartLog_Trait;

    public function Create(): void{
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 9596);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'MICHI_RequestStatus($_IPS[\'TARGET\']);');



        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', 'Power', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Power'
        ], 10);
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('Dimmer', 'Display Helligkeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Bulb',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP'         => 25,
            'SUFFIX'       => '%'
        ], 20);
        $this->EnableAction('Dimmer');

        $this->RegisterVariableString('Model', 'Modell', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 30);
        
        $this->RegisterVariableString('Version', 'Software Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 40);
        
        $this->RegisterVariableString('IP', 'IP-Adresse', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Network'
        ], 50);
        
        $this->RegisterVariableString('MAC', 'MAC-Adresse', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Network'
        ], 60);
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();

        // Timer setzen
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 1000);
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }

        // Initiale Sichtbarkeit der Variablen setzen
        $this->UpdatePowerState($this->GetValue('Power'));
    }

    public function RequestAction(string $Ident, mixed $Value): void{
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->SendCommand("power_on!");
                } else {
                    $this->SendCommand("power_off!");
                }
                $this->UpdatePowerState($Value);
                break;
            case 'Dimmer':
                // Google Home liefert 0-100%. Michi erwartet 0 (am hellsten) bis 4 (am dunkelsten).
                $val = 4 - (int)round((max(0, min(100, (int)$Value))) / 25);
                $this->SendCommand("dimmer_" . $val . "!");
                $this->SetValue($Ident, $Value);
                break;
        }
        
        usleep(500000);
        $this->RequestStatus();
    }

    public function RequestStatus(): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');

        if (empty($host)) {
            $this->SendDebug("Log", "RequestStatus abgebrochen: Keine IP-Adresse (Host) konfiguriert!", 0);
            return;
        }

        $this->SendDebug("Log", "Verbinde mit Michi $host:$port...", 0);
        
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) {
            $this->SendDebug("Log", "Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            
            // Michi ist vermutlich im Standby oder stromlos
            if ($this->GetValue('Power')) {
                $this->UpdatePowerState(false);
                $this->SendDebug("TIMEOUT", "Keine Verbindung möglich. Setze Power auf Aus.", 0);
            }
            return;
        }
        
        $commands = [
            'dimmer?',
            'source?'
        ];
        
        foreach ($commands as $cmd) {
            $this->SendDebug("Transmit", $cmd . "!", 0);
            fwrite($fp, $cmd . "!");
            usleep(100000);
        }
        
        $this->ReadResponse($fp);
        fclose($fp);
    }

    private function SendCommand(string $cmd): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');

        if (empty($host)) return;

        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) {
            $this->SendDebug("Log", "Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            return;
        }
        
        $cmd = rtrim($cmd, '!');
        $this->SendDebug("Transmit", $cmd . "!", 0);
        fwrite($fp, $cmd . "!");
        usleep(100000);
        
        $this->ReadResponse($fp);
        fclose($fp);
    }

    private function ReadResponse($fp): void
    {
        stream_set_blocking($fp, false);
        $response = "";
        $startTime = microtime(true);
        
        while (microtime(true) - $startTime < 1.0) {
            $chunk = fread($fp, 1024);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;
            }
            usleep(50000);
        }
        
        if (!empty($response)) {
            $this->SendDebug("Receive", $response, 0);
            $parts = explode('$', $response);
            foreach ($parts as $part) {
                $part = trim($part);
                if (!empty($part)) {
                    $this->ParseLine($part);
                }
            }
        }
    }

    private function ParseLine(string $msg): void
    {
        $this->SendDebug("MESSAGE", $msg, 0);

        // Die Nachrichten haben das Format: variable=wert
        $parts = explode('=', $msg, 2);
        if (count($parts) != 2) return;

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        
        $lowerKey = strtolower($key);
        
        if ($lowerKey === 'dimmer' || $lowerKey === 'source') {
            if (!$this->GetValue('Power')) {
                $this->UpdatePowerState(true);
            }
        }

        switch ($lowerKey) {
            case 'power':
                if ($value === 'on') {
                    $this->UpdatePowerState(true);
                } elseif ($value === 'standby') {
                    $this->UpdatePowerState(false);
                }
                break;
            case 'dimmer':
                // Michi liefert 0 (am hellsten) bis 4 (am dunkelsten). Umrechnung in 0-100%
                $symconVal = (4 - (int)$value) * 25;
                $this->SetValue('Dimmer', $symconVal);
                break;
            case 'version':
                $this->SetValue('Version', $value);
                break;
            case 'model':
                $this->SetValue('Model', $value);
                break;
            case 'ip':
                $this->SetValue('IP', $value);
                break;
            case 'mac':
                $this->SetValue('MAC', $value);
                break;
        }
    }

    private function UpdatePowerState(bool $state): void
    {
        if ($this->GetValue('Power') !== $state) {
            $this->SetValue('Power', $state);
        }
        
        $hide = !$state;
        $this->SetHiddenSafe('Dimmer', $hide);
        $this->SetHiddenSafe('Model', $hide);
        $this->SetHiddenSafe('Version', $hide);
        $this->SetHiddenSafe('IP', $hide);
        $this->SetHiddenSafe('MAC', $hide);
    }

    private function SetHiddenSafe(string $ident, bool $hidden): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && $id > 0) {
            IPS_SetHidden($id, $hidden);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'Michi: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Hallo! Hier konfigurierst du die Verbindung zu deinem Michi-Gerät. Trage einfach die IP-Adresse und den passenden Port ein."
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
                }
            ]
        },
        {
            "type": "Label",
            "label": "Wie oft soll ich bei Michi nach dem aktuellen Status fragen? Stell hier das Intervall in Sekunden ein. Wenn du 0 einträgst, frage ich gar nicht mehr automatisch nach."
        },
        {
            "type": "NumberSpinner",
            "name": "UpdateInterval",
            "caption": "Abfrage-Intervall (Sekunden)",
            "minimum": 0,
            "maximum": 3600
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Alle Werte aktualisieren",
            "onClick": "MICHI_RequestStatus($id);"
        }
    ]
}
EOT;
    }
}
