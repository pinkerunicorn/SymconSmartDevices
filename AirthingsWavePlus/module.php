<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class AirthingsWavePlus extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();
        $this->DA_RegisterWatchdog();
        $this->DA_RegisterAvailability(900); // Alarm priority: 0 (Low - it's just an air quality sensor)

        // Properties for MQTT
        $this->RegisterPropertyString('MQTTBaseTopic', 'airthings01');
        $this->RegisterPropertyInteger('Timeout', 30); // 30 minutes default timeout
        $this->RegisterPropertyInteger('RadonThresholdMedium', 100);
        $this->RegisterPropertyInteger('RadonThresholdHigh', 200);
        $this->RegisterPropertyInteger('RadonThresholdCritical', 300);
        $this->SetReceiveDataFilter('.*' . preg_quote($this->ReadPropertyString('MQTTBaseTopic')) . '.*');

        // Variables
        $onlineOptions = json_encode([
            ['Value' => false, 'Caption' => 'Offline', 'IconValue' => 'network-wired', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000],
            ['Value' => true, 'Caption' => 'Online', 'IconValue' => 'network-wired', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00]
        ]);
        $this->RegisterVariableBoolean('Online', 'Online', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'network-wired',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $onlineOptions
        ]);
        
        $alarmOptions = json_encode([
            ['Value' => false, 'Caption' => 'OK', 'IconValue' => 'bell', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
            ['Value' => true, 'Caption' => 'Alarm!', 'IconValue' => 'bell', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000]
        ]);
        $this->RegisterVariableBoolean('Alarm', 'Alarm', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'bell',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $alarmOptions
        ]);
        $this->RegisterVariableFloat('AirTemp', 'Temperatur', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' Â°C', 'ICON' => 'temperature-half']);
        $this->RegisterVariableFloat('AirHum', 'Luftfeuchtigkeit', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'ICON' => 'droplet']);
        $this->RegisterVariableFloat('AirPress', 'Luftdruck', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' hPa', 'ICON' => 'gauge-high']);
        $this->RegisterVariableFloat('AirBatt', 'Batterie', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'ICON' => 'battery-full']);
        $this->RegisterVariableInteger('AirCO2', 'CO2', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' ppm', 'ICON' => 'smog']);
        $this->RegisterVariableInteger('AirVOC', 'VOC', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' ppb', 'ICON' => 'smog']);
        $this->RegisterVariableInteger('AirRadonST', 'Radon (Short Term)', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' Bq/mÂ³', 'ICON' => 'radiation']);
        $this->RegisterVariableInteger('AirRadonLT', 'Radon (Long Term)', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' Bq/mÂ³', 'ICON' => 'radiation']);
        
        $radonIntervals = json_encode([
            ['IntervalMinValue' => 0, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Gut', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'circle-check', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 1, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Mittel', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xFFFF00, 'ContentColorActive' => true, 'ContentColorValue' => 0x000000],
            ['IntervalMinValue' => 2, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Hoch', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'bell', 'ColorActive' => true, 'ColorValue' => 0xFF9900, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 3, 'IntervalMaxValue' => 4, 'ConstantActive' => true, 'ConstantValue' => 'Kritisch', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'bell', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF]
        ]);
        $this->RegisterVariableInteger('AirRadonStatus', 'Radon Status', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'radiation',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $radonIntervals
        ], 10);

        // Timer
        $this->RegisterTimer('WatchdogTimer', 0, 'AIRTHINGS_WatchdogTriggered($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // Register MQTT Filter
        $topic = $this->ReadPropertyString('MQTTBaseTopic');
        $this->SetReceiveDataFilter('.*' . preg_quote($topic) . '.*');


        // Reset Watchdog Timer if enabled
        $this->ResetWatchdog();
                $this->DA_ResetWatchdog(1800);
                $this->DA_SetAvailable(true);

    }
    
    public function WatchdogTriggered(): void
    {
        // Timer fired -> no data received for 'Timeout' minutes
        $this->SetTimerInterval('WatchdogTimer', 0); // Stop timer until new data arrives
        $this->SetValue('Online', false);
        $this->SetValue('Alarm', true);
        $this->SLogInfo('AirthingsWavePlus: Watchdog ausgelöst: Keine Daten seit ' . $this->ReadPropertyInteger('Timeout') . ' Minuten empfangen!');
    }
    
    private function ResetWatchdog(): void
    {
        $timeout = $this->ReadPropertyInteger('Timeout');
        if ($timeout > 0) {
            $this->SetTimerInterval('WatchdogTimer', $timeout * 60 * 1000);
        } else {
            $this->SetTimerInterval('WatchdogTimer', 0);
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->SLog('ERROR', 'Ungültige JSON-Daten empfangen', json_last_error_msg());
                return 'NOK';
            }
            
            // Standard MQTT Splitter payload structure in IP-Symcon
            if (!isset($data->Topic) || !isset($data->Payload)) {
                return "NOK";
            }
            $topic = $data->Topic;
            
            // IP-Symcon MQTT Splitter always passes Payload as a hex string
            $payloadRaw = is_scalar($data->Payload) ? (string)$data->Payload : '';
            $payloadStr = $payloadRaw;
            if (ctype_xdigit($payloadRaw) && strlen($payloadRaw) % 2 === 0) {
                $payloadStr = hex2bin($payloadRaw);
            }
            
            $base = $this->ReadPropertyString('MQTTBaseTopic');

            // Set general device online status from ESPHome LWT if available
            if ($topic === $base . '/status') {
                $isOnline = (strtolower($payloadStr) === 'online');
                $this->SetValue('Online', $isOnline);
                if ($isOnline) {
                    $this->SetValue('Alarm', false);
                    $this->ResetWatchdog();
                $this->DA_ResetWatchdog(1800);
                $this->DA_SetAvailable(true);
                } else {
                    $this->SetValue('Alarm', true);
                    $this->SetTimerInterval('WatchdogTimer', 0);
                }
                return "OK";
            }

            // Check if the topic belongs to us (e.g. "airthings01/sensor/waveplus_temperature/state")
            if (strpos($topic, $base) !== false) {
                $value = floatval($payloadStr);
                
                // ESPHome sends 'nan' if a sensor is currently unavailable
                if (!is_finite($value)) {
                    return "OK"; // Ignore NaN / INF values
                }

                $updated = false;
                
                // Map ESPHome default topic names to variables
                // Use @IPS_GetObjectIDByIdent instead of GetIDForIdent to avoid Exceptions in Strict Mode
                if (strpos($topic, 'temp') !== false && @IPS_GetObjectIDByIdent('AirTemp', $this->InstanceID) !== false) {
                    $this->SetValue('AirTemp', $value);
                    $updated = true;
                } elseif (strpos($topic, 'hum') !== false && @IPS_GetObjectIDByIdent('AirHum', $this->InstanceID) !== false) {
                    $this->SetValue('AirHum', $value);
                    $updated = true;
                } elseif (strpos($topic, 'press') !== false && @IPS_GetObjectIDByIdent('AirPress', $this->InstanceID) !== false) {
                    $this->SetValue('AirPress', $value);
                    $updated = true;
                } elseif (strpos($topic, 'batt') !== false && @IPS_GetObjectIDByIdent('AirBatt', $this->InstanceID) !== false) {
                    // ESPHome liefert Spannung (z.B. 3.3V). Airthings verwendet 2x AA Batterien.
                    // Voll = 3.3V (100%), Leer = ~2.2V (0%)
                    $pct = (($value - 2.2) / (3.3 - 2.2)) * 100;
                    $pct = max(0, min(100, $pct)); // Clamp between 0 and 100
                    $this->SetValue('AirBatt', round($pct));
                    $updated = true;
                } elseif (strpos($topic, 'co2') !== false && @IPS_GetObjectIDByIdent('AirCO2', $this->InstanceID) !== false) {
                    $this->SetValue('AirCO2', (int)$value);
                    $updated = true;
                } elseif ((strpos($topic, 'voc') !== false || strpos($topic, 'tvoc') !== false) && @IPS_GetObjectIDByIdent('AirVOC', $this->InstanceID) !== false) {
                    $this->SetValue('AirVOC', (int)$value);
                    $updated = true;
                } elseif ((strpos($topic, 'radon_long_term') !== false || strpos($topic, 'radon_lt') !== false) && @IPS_GetObjectIDByIdent('AirRadonLT', $this->InstanceID) !== false) {
                    $this->SetValue('AirRadonLT', (int)$value);
                    $updated = true;
                } elseif (strpos($topic, 'radon') !== false && @IPS_GetObjectIDByIdent('AirRadonST', $this->InstanceID) !== false) {
                    // Check if it's not the long term to avoid double matching
                    if (strpos($topic, 'long') === false && strpos($topic, 'lt') === false) {
                        $radonVal = (int)$value;
                        $this->SetValue('AirRadonST', $radonVal);
                        $updated = true;
                        
                        $tMed = $this->ReadPropertyInteger('RadonThresholdMedium');
                        $tHigh = $this->ReadPropertyInteger('RadonThresholdHigh');
                        $tCrit = $this->ReadPropertyInteger('RadonThresholdCritical');
                        
                        $status = 0; // Gut
                        if ($radonVal >= $tCrit) {
                            $status = 3;
                        } elseif ($radonVal >= $tHigh) {
                            $status = 2;
                        } elseif ($radonVal >= $tMed) {
                            $status = 1;
                        }
                        if (@IPS_GetObjectIDByIdent('AirRadonStatus', $this->InstanceID) !== false) {
                            $this->SetValue('AirRadonStatus', $status);
                        }
                    }
                }
                
                // Reset Watchdog on any sensor update
                if ($updated) {
                    $this->SetValue('Online', true);
                    $this->SetValue('Alarm', false);
                    $this->ResetWatchdog();
                $this->DA_ResetWatchdog(1800);
                $this->DA_SetAvailable(true);
                }
            }

            return "OK";
        } catch (Throwable $e) {
            $this->SLogError('AirthingsWavePlus: ReceiveData Exception: ' . $e->getMessage());
            return "NOK";
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                break;
        }
    }

    public function RequestUpdate(): void
    {
        if (!$this->HasActiveParent()) {
            echo "Kein aktiver MQTT Server verbunden!";
            return;
        }

        $base = $this->ReadPropertyString('MQTTBaseTopic');
        $topic = $base . '/update/command';

        $data = [
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType' => 3,
            'QualityOfService' => 0,
            'Retain' => false,
            'Topic' => $topic,
            'Payload' => 'PRESS'
        ];

        $this->SendDataToParent(json_encode($data));
        echo "Update-Anfrage an ESPHome gesendet ($topic)!";
    }

    public function GetConfigurationForm(): string
    {
        $elements = [];
        $elements[] = ["type" => "ValidationTextBox", "name" => "MQTTBaseTopic", "caption" => "MQTT Base Topic"];
        $elements[] = ["type" => "NumberSpinner", "name" => "Timeout", "caption" => "Watchdog Timeout (Minuten)"];
        $elements[] = ["type" => "Label", "caption" => " "];
        $elements[] = ["type" => "Label", "caption" => "Radon Ampel Schwellwerte (Bq/m³)"];
        $elements[] = ["type" => "NumberSpinner", "name" => "RadonThresholdMedium", "caption" => "Schwelle 'Mittel'"];
        $elements[] = ["type" => "NumberSpinner", "name" => "RadonThresholdHigh", "caption" => "Schwelle 'Hoch'"];
        $elements[] = ["type" => "NumberSpinner", "name" => "RadonThresholdCritical", "caption" => "Schwelle 'Kritisch'"];
        
        return json_encode(["elements" => $elements]);
    }
}
