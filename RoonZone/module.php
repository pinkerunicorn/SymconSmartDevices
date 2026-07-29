<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
class RoonZone extends IPSModuleStrict
{
    use SmartLog_Trait;
    private const TRANSPORT_COMMANDS = [
        0 => 'previous',
        1 => 'stop',
        2 => 'play',
        3 => 'pause',
        4 => 'next',
    ];

    public function Create(): void
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterPropertyString('ZoneName', '');

        // Variablen registrieren
        $this->RegisterVariableInteger('State', 'ℹ Status', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'Roon.State'
        ], 1);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Information');
        $this->RegisterVariableString('Title', '🎵 Titel', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Melody'], 2);
        $this->RegisterVariableString('Artist', '🎤 Künstler', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'User'], 3);
        $this->RegisterVariableString('Album', '💿 Album', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Database'], 4);
        $this->RegisterVariableInteger('Volume', '🔊 Lautstärke', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Intensity',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP'         => 1,
            'SUFFIX'       => '%'
        ], 5);

        // Aktionen für die Bedienung freigeben
        $this->EnableAction('State');
        $this->EnableAction('Volume');
    }

    public function ApplyChanges(): void
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $zone = $this->ReadPropertyString('ZoneName');
        if (empty($zone)) {
            $this->SetStatus(104); // IS_INACTIVE
            return;
        }
        $this->SetStatus(102); // IS_ACTIVE

        $topicZone = $this->GetMqttZoneName($zone);

        // Filter setzen (mit preg_quote für Sonderzeichen-Sicherheit)
        $this->SetReceiveDataFilter('.*' . preg_quote($topicZone, '/') . '.*');

        if (!IPS_VariableProfileExists('Roon.State')) {
            IPS_CreateVariableProfile('Roon.State', 1);
            IPS_SetVariableProfileAssociation('Roon.State', 0, 'Previous', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 1, 'Stop', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 2, 'Play', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 3, 'Pause', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 4, 'Next', '', -1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('State'), 'Roon.State');
    }

    public function ReceiveData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString, true);
            if (!is_array($data)) return 'NOK';
            if (!isset($data['Topic']) || !isset($data['Payload'])) {
                return 'NOK';
            }

            $topic      = (string) $data['Topic'];
            $payloadRaw = is_scalar($data['Payload']) ? (string) $data['Payload'] : '';

            // Hex-Dekodierung (konsistent mit Airthings/SmartWater: nur bei gültigem Hex UND gerader Länge)
            $payload = (ctype_xdigit($payloadRaw) && strlen($payloadRaw) % 2 === 0)
                ? hex2bin($payloadRaw)
                : $payloadRaw;

            $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));

            // Titel
            if ($topic === 'roon/' . $topicZone . '/now_playing/three_line/line1') {
                $this->SetValue('Title', $payload);
            }
            // Künstler
            elseif ($topic === 'roon/' . $topicZone . '/now_playing/three_line/line2') {
                $this->SetValue('Artist', $payload);
            }
            // Album
            elseif ($topic === 'roon/' . $topicZone . '/now_playing/three_line/line3') {
                $this->SetValue('Album', $payload);
            }
            // Status
            elseif ($topic === 'roon/' . $topicZone . '/state') {
                switch (strtolower($payload)) {
                    case 'stopped':  $this->SetValue('State', 1); break;
                    case 'playing':  $this->SetValue('State', 2); break;
                    case 'paused':   $this->SetValue('State', 3); break;
                    case 'loading':  $this->SetValue('State', 1); break;
                }
            }

            // Lautstärke: roon/zonename/outputs/outputname/volume/value
            if (preg_match('/^roon\/' . preg_quote($topicZone, '/') . '\/outputs\/(.+)\/volume\/value$/', $topic, $matches)) {
                $outputName = $matches[1];
                $this->SetBuffer('OutputName', $outputName);

                // Konvertiere dB (-60 bis 0) in % (0 bis 100)
                $db      = max(-60, min(0, (int) $payload));
                $percent = (int) round(($db + 60) * 100 / 60);
                $this->SetValue('Volume', $percent);
            }

            return 'OK';
        } catch (\Throwable $e) {
            $this->SLog('ERROR', 'ReceiveData Exception: ' . $e->getMessage());
            return 'NOK';
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'State':
                if (isset(self::TRANSPORT_COMMANDS[$Value])) {
                    $this->SendCommand(self::TRANSPORT_COMMANDS[$Value]);
                } else {
                    $this->SLog('ERROR', 'Unbekannte Transport-Aktion', 'Ident: ' . $Ident . ' | Wert: ' . $Value);
                }
                break;
            case 'Volume':
                $outputName = $this->GetBuffer('OutputName');
                if ($outputName !== '') {
                    $percent = max(0, min(100, (int) $Value));
                    $db      = (int) round(($percent * 60 / 100) - 60);
                    $this->SendMQTTVolumeCommand($outputName, 'set', (string)$db);
                }
                break;
        }
    }

    private function SendMQTTVolumeCommand(string $outputName, string $command, string $payload): void
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/' . $topicZone . '/outputs/' . $outputName . '/volume/' . $command;
        $this->PublishMqtt($topic, $payload);
    }

    public function SendCommand(string $command): void
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/' . $topicZone . '/command';
        $this->PublishMqtt($topic, $command);
    }

    public function SetVolume(int $volume): void
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/' . $topicZone . '/volume/set';
        $this->PublishMqtt($topic, (string)$volume);
    }

    public function TogglePlayPause(): void { $this->SendCommand('playpause'); }
    public function NextTrack(): void       { $this->SendCommand('next'); }
    public function PreviousTrack(): void   { $this->SendCommand('previous'); }

    private function PublishMqtt(string $topic, string $payload): void
    {
        if (!$this->HasActiveParent()) {
            $this->SLog('WARNING', 'No active MQTT parent');
            return;
        }
        $data = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => bin2hex($payload)
        ];
        $this->SendDataToParent(json_encode($data));
    }

    private function GetMqttZoneName(string $zoneName): string
    {
        // Den exakten Zonen-Namen zurückgeben — roon-extension-mqtt erlaubt Leerzeichen
        return $zoneName;
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'RoonZone: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {"type": "Label", "label": "Hier stellst du den Namen deiner Roon Zone ein..."},
        {"type": "RowLayout", "items": [
            {"type": "ValidationTextBox", "name": "ZoneName", "caption": "Roon Zone Name (exakte Schreibweise aus Roon)"}
        ]}
    ],
    "actions": [
        {"type": "Button", "label": "Play / Pause umschalten", "onClick": "ROON_TogglePlayPause($id);"}
    ],
    "status": [
        {"code": 102, "icon": "active",   "caption": "Zone ist konfiguriert."},
        {"code": 104, "icon": "inactive", "caption": "Kein Zonenname konfiguriert."}
    ]
}
EOT;
    }
}
