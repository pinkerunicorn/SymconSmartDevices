<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class EMSESPDevice extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    private const KEY_MAP = [
        'curflowtemp'     => ['name' => 'Vorlauftemperatur', 'icon' => 'temperature', 'suffix' => ' Ã‚Â°C', 'decimals' => 1],
        'outdoortemp'     => ['name' => 'AuÃƒÅ¸entemperatur', 'icon' => 'temperature', 'suffix' => ' Ã‚Â°C', 'decimals' => 1],
        'rettemp'         => ['name' => 'RÃƒÂ¼cklauftemperatur', 'icon' => 'temperature', 'suffix' => ' Ã‚Â°C', 'decimals' => 1],
        'seltemp'         => ['name' => 'Soll-Temperatur', 'icon' => 'temperature', 'suffix' => ' Ã‚Â°C', 'decimals' => 1],
        'daytemp'         => ['name' => 'Tag-Temperatur', 'icon' => 'temperature', 'suffix' => ' Ã‚Â°C', 'decimals' => 1],
        'nighttemp'       => ['name' => 'Nacht-Temperatur', 'icon' => 'temperature', 'suffix' => ' Ã‚Â°C', 'decimals' => 1],
        'manualtemp'      => ['name' => 'Manuelle Temperatur', 'icon' => 'temperature', 'suffix' => ' Ã‚Â°C', 'decimals' => 1],
        'heatingactive'   => ['name' => 'Heizung aktiv', 'icon' => 'power'],
        'heatingpumpmod'  => ['name' => 'Pumpenleistung', 'icon' => 'speedometer', 'suffix' => ' %', 'decimals' => 0],
        'mode'            => ['name' => 'Betriebsmodus', 'icon' => 'cog'],
        'wwcharge'        => ['name' => 'Warmwasserladung', 'icon' => 'water'],
        'tapwateractive'  => ['name' => 'Warmwasser aktiv', 'icon' => 'water'],
    ];

    private const MODE_MAP = [
        'auto'   => 0,
        'manual' => 1,
        'off'    => 2
    ];

    private const MODE_MAP_REVERSE = [
        0 => 'auto',
        1 => 'manual',
        2 => 'off'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterWatchdog();
        $this->DA_RegisterAvailability(900); // Alarm priority: 2 (High - heating system)
        $this->RegisterPropertyString('MQTTTopic', 'ems-esp');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if (IPS_VariableProfileExists('EMSESP.Mode')) {
            IPS_DeleteVariableProfile('EMSESP.Mode');
        }

        $topic = $this->ReadPropertyString('MQTTTopic');
        $this->SetReceiveDataFilter('.*' . preg_quote($topic, '.') . '.*');

    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Buffer'])) {
            return "";
        }
        
        $payload = json_decode($data['Buffer'], true);
        if (!$payload || !isset($payload['Topic']) || !isset($payload['Payload'])) {
            return "";
        }
        
        $topic = $payload['Topic'];
        $message = $payload['Payload'];
        
        $this->SendDebug("MQTT RX Topic", $topic, 0);
        $this->SendDebug("MQTT RX Payload", $message, 0);
        
        $this->ProcessMQTTMessage($topic, $message);
        $this->DA_ResetWatchdog(300);
        $this->DA_SetAvailable(true);
        
        return "OK";
    }

    private function ProcessMQTTMessage(string $topic, string $message): void
    {
        $baseTopic = $this->ReadPropertyString('MQTTTopic');
        
        if (strpos($topic, $baseTopic) !== 0) {
            return;
        }
        
        $data = json_decode($message, true);
        if ($data === null) {
            return;
        }
        
        $subTopic = substr($topic, strlen($baseTopic) + 1);
        $prefix = str_replace('/', '_', $subTopic);
        
        $this->ParseJSONPayload($prefix, $data);
    }

    private function ParseJSONPayload(string $prefix, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->ParseJSONPayload($prefix . '_' . $key, $value);
            } else {
                $this->UpdateOrCreateVariable($prefix . '_' . $key, $key, $value);
            }
        }
    }

    private function UpdateOrCreateVariable(string $ident, string $rawKey, $value): void
    {
        $lowerKey = strtolower($rawKey);
        $keyInfo = self::KEY_MAP[$lowerKey] ?? null;
        $name = $keyInfo['name'] ?? ucfirst($rawKey);
        $icon = $keyInfo['icon'] ?? '';

        // Normalize string booleans "on"/"off"
        if ($value === 'on' || $value === 'off') {
            $value = ($value === 'on');
        }

        // Normalize mode string to integer if mode key
        if ($lowerKey === 'mode' && is_string($value)) {
            $value = self::MODE_MAP[strtolower($value)] ?? 0;
        }

        $writableKeys = ['seltemp', 'mode', 'daytemp', 'nighttemp', 'manualtemp', 'heatingoff', 'wwcharge', 'setpoint'];
        $isWritable = in_array($lowerKey, $writableKeys);

        $type = 3; // String
        $profile = '';

        if (is_bool($value)) {
            $type = 0; // Boolean
        } elseif ($lowerKey === 'mode') {
            $type = 1; // Integer
        } elseif (is_int($value) || is_float($value)) {
            if (strpos($lowerKey, 'temp') !== false) {
                $type = 2; // Float
                // Temperature scaling check: divide by 10 if integer > 60
                if (is_int($value) && $value > 60) {
                    $value = $value / 10.0;
                } else {
                    $value = (float)$value;
                }
                if ($isWritable) {
                    $profile = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Temperature', 'SUFFIX' => ' °C', 'DECIMALPLACES' => 1];
                }
            } elseif (is_int($value)) {
                $type = 1; // Integer
            } else {
                $type = 2; // Float
            }
        }

        $presArray = [];
        if ($isWritable) {
            $this->EnableAction($ident);
            if ($lowerKey === 'mode') {
                $modeOptions = json_encode([
                    ['Value' => 0, 'Caption' => 'Auto', 'IconActive' => true, 'IconValue' => 'calendar', 'Color' => -1],
                    ['Value' => 1, 'Caption' => 'Manuell', 'IconActive' => true, 'IconValue' => 'user', 'Color' => -1],
                    ['Value' => 2, 'Caption' => 'Aus', 'IconActive' => true, 'IconValue' => 'power', 'Color' => -1]
                ]);
                $presArray = [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'cog',
                    'OPTIONS' => $modeOptions
                ];
            }
        } else {
            if (is_bool($value)) {
                $boolOptions = json_encode([
                    ['Value' => false, 'Caption' => 'Aus', 'IconValue' => 'power', 'IconActive' => true,
                     'ColorActive' => true, 'ColorDisplay' => 0x888888, 'ContentColorActive' => false,
                     'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x888888],
                    ['Value' => true, 'Caption' => 'An', 'IconValue' => 'power', 'IconActive' => true,
                     'ColorActive' => true, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false,
                     'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00]
                ]);
                $presArray = [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'ICON' => $icon ?: 'power',
                    'COLOR' => -1,
                    'CONTENT_COLOR' => -1,
                    'DISPLAY_TYPE' => 0,
                    'PREVIEW_STYLE' => 1,
                    'SHOW_PREVIEW' => true,
                    'OPTIONS' => $boolOptions
                ];
            } else {
                $presArray = [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
                ];
                if ($icon !== '') {
                    $presArray['ICON'] = $icon;
                }
                if (isset($keyInfo['suffix'])) {
                    $presArray['SUFFIX'] = $keyInfo['suffix'];
                }
                if (isset($keyInfo['decimals'])) {
                    $presArray['DECIMALS'] = $keyInfo['decimals'];
                }
            }
        }

        switch ($type) {
            case 0: $this->RegisterVariableBoolean($ident, $name, $presArray ?: $profile, 0); break;
            case 1: $this->RegisterVariableInteger($ident, $name, $presArray ?: $profile, 0); break;
            case 2: $this->RegisterVariableFloat($ident, $name, $presArray ?: $profile, 0); break;
            case 3: $this->RegisterVariableString($ident, $name, $presArray ?: $profile, 0); break;
        }

        $varID = $this->GetIDForIdent($ident);

        if (!$varID) {
            return;
        }


        $this->SetValue($ident, $value);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'DA_Watchdog') {
            $this->DA_HandleWatchdog();
            return;
        }
        $baseTopic = $this->ReadPropertyString('MQTTTopic');
        
        $parts = explode('_', $Ident);
        $cmd = array_pop($parts);
        $deviceType = $parts[0];
        
        if ($deviceType === 'thermostat' || $deviceType === 'boiler' || $deviceType === 'mixer') {
            $cmdTopic = $baseTopic . '/' . $deviceType . '_cmd';
        } else {
            $cmdTopic = $baseTopic . '/system_cmd';
        }

        $sendValue = $Value;
        if (strtolower($cmd) === 'mode' && is_int($Value)) {
            $sendValue = self::MODE_MAP_REVERSE[$Value] ?? 'auto';
        }

        $payload = json_encode([
            'cmd' => $cmd,
            'value' => $sendValue
        ]);
        
        $data = [
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType' => 3,
            'QualityOfService' => 0,
            'Retain' => false,
            'Topic' => $cmdTopic,
            'Payload' => $payload
        ];
        
        $this->SendDataToParent(json_encode($data));
        
        $this->SetValue($Ident, $Value);
    }

    public function ProcessTestPayload(string $Topic, string $Payload): void
    {
        $this->ProcessMQTTMessage($Topic, $Payload);
    }
}
