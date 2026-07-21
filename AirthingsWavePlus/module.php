<?php

declare(strict_types=1);

class AirthingsWavePlus extends IPSModuleStrict
{
    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Properties for MQTT
        $this->RegisterPropertyString('MQTTBaseTopic', 'airthings01');
        $this->RegisterPropertyInteger('Timeout', 30); // 30 minutes default timeout
        $this->SetReceiveDataFilter('.*' . preg_quote($this->ReadPropertyString('MQTTBaseTopic')) . '.*');

        // Variables
        $this->RegisterVariableBoolean('Online', 'Online');
        IPS_SetIcon($this->GetIDForIdent('Online'), 'Network');
        $this->RegisterVariableBoolean('Alarm', 'Alarm');
        IPS_SetIcon($this->GetIDForIdent('Alarm'), 'Warning');
        $this->RegisterVariableFloat('AirTemp', 'Temperatur');
        IPS_SetIcon($this->GetIDForIdent('AirTemp'), 'Temperature');
        $this->RegisterVariableFloat('AirHum', 'Luftfeuchtigkeit');
        IPS_SetIcon($this->GetIDForIdent('AirHum'), 'Drop');
        $this->RegisterVariableFloat('AirPress', 'Luftdruck');
        IPS_SetIcon($this->GetIDForIdent('AirPress'), 'Cloud');
        $this->RegisterVariableFloat('AirBatt', 'Batterie');
        IPS_SetIcon($this->GetIDForIdent('AirBatt'), 'Battery');
        $this->RegisterVariableInteger('AirCO2', 'CO2');
        IPS_SetIcon($this->GetIDForIdent('AirCO2'), 'Cloud');
        $this->RegisterVariableInteger('AirVOC', 'VOC');
        IPS_SetIcon($this->GetIDForIdent('AirVOC'), 'Cloud');
        $this->RegisterVariableInteger('AirRadonST', 'Radon (Short Term)');
        IPS_SetIcon($this->GetIDForIdent('AirRadonST'), 'Cloud');
        $this->RegisterVariableInteger('AirRadonLT', 'Radon (Long Term)');
        IPS_SetIcon($this->GetIDForIdent('AirRadonLT'), 'Cloud');
        
        // Timer
        $this->RegisterTimer('WatchdogTimer', 0, 'AIRTHINGS_WatchdogTriggered($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Register MQTT Filter
        $topic = $this->ReadPropertyString('MQTTBaseTopic');
        $this->SetReceiveDataFilter('.*' . preg_quote($topic) . '.*');

        // Apply Symcon 8 Custom Presentations (instead of Legacy Profiles)
        $this->UpdatePresentations();
        
        // Reset Watchdog Timer if enabled
        $this->ResetWatchdog();
    }
    
    public function WatchdogTriggered(): void
    {
        // Timer fired -> no data received for 'Timeout' minutes
        $this->SetTimerInterval('WatchdogTimer', 0); // Stop timer until new data arrives
        $this->SetValue('Online', false);
        $this->SetValue('Alarm', true);
        IPS_LogMessage('AirthingsWavePlus', 'Watchdog ausgelöst: Keine Daten seit ' . $this->ReadPropertyInteger('Timeout') . ' Minuten empfangen!');
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

    private function UpdatePresentations(): void
    {
        // Online Status
        if (@IPS_GetObjectIDByIdent('Online', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('Online'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Network'
            ]);
        }
        
        // Alarm Status
        if (@IPS_GetObjectIDByIdent('Alarm', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('Alarm'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Alert'
            ]);
        }

        // Temperatur
        if (@IPS_GetObjectIDByIdent('AirTemp', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirTemp'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' °C',
                'ICON'         => 'Temperature'
            ]);
        }
        
        // Luftfeuchtigkeit
        if (@IPS_GetObjectIDByIdent('AirHum', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirHum'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' %',
                'ICON'         => 'Drops'
            ]);
        }
        
        // Luftdruck
        if (@IPS_GetObjectIDByIdent('AirPress', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirPress'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' hPa',
                'ICON'         => 'Gauge'
            ]);
        }

        // Batterie (wird als % dargestellt)
        if (@IPS_GetObjectIDByIdent('AirBatt', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirBatt'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' %', 
                'ICON'         => 'Battery'
            ]);
        }

        // CO2
        if (@IPS_GetObjectIDByIdent('AirCO2', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirCO2'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' ppm',
                'ICON'         => 'Wind'
            ]);
        }

        // VOC
        if (@IPS_GetObjectIDByIdent('AirVOC', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirVOC'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' ppb',
                'ICON'         => 'Wind'
            ]);
        }

        // Radon ST / LT
        if (@IPS_GetObjectIDByIdent('AirRadonST', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirRadonST'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' Bq/m³',
                'ICON'         => 'Radiation'
            ]);
        }
        if (@IPS_GetObjectIDByIdent('AirRadonLT', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AirRadonLT'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' Bq/m³',
                'ICON'         => 'Radiation'
            ]);
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString);
            
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
                        $this->SetValue('AirRadonST', (int)$value);
                        $updated = true;
                    }
                }
                
                // Reset Watchdog on any sensor update
                if ($updated) {
                    $this->SetValue('Online', true);
                    $this->SetValue('Alarm', false);
                    $this->ResetWatchdog();
                }
            }

            return "OK";
        } catch (Throwable $e) {
            IPS_LogMessage('AirthingsWavePlus', 'ReceiveData Exception: ' . $e->getMessage());
            return "NOK";
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
}
