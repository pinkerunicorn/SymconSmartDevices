<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class GardenaValve extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    use DeviceRegistration_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('ValveID', '');

        $this->DA_RegisterAvailability(900);

        // --- Ventilstatus (Read-only Integer mit Intervallen) ---
        $activityIntervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 1,
                'ConstantActive' => true, 'ConstantValue' => 'Geschlossen',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'faucet',
                'ColorActive' => true, 'ColorValue' => 0x808080,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 1, 'IntervalMaxValue' => 2,
                'ConstantActive' => true, 'ConstantValue' => 'Manuelle Bewaesserung',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'droplet',
                'ColorActive' => true, 'ColorValue' => 0x2196F3,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 2, 'IntervalMaxValue' => 3,
                'ConstantActive' => true, 'ConstantValue' => 'Zeitplan aktiv',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'calendar-days',
                'ColorActive' => true, 'ColorValue' => 0x00CC00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 3, 'IntervalMaxValue' => 4,
                'ConstantActive' => true, 'ConstantValue' => 'Aus',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Cross',
                'ColorActive' => true, 'ColorValue' => 0xFF4444,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ]
        ]);

        $this->RegisterVariableInteger('ValveActivity', 'Ventilstatus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'faucet',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $activityIntervals
        ], 1);

        // --- Bewässerung (Switch mit EnableAction) ---
        $this->RegisterVariableBoolean('Watering', 'Bewaesserung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'droplet'
        ], 2);
        $this->EnableAction('Watering');

        // --- Restlaufzeit in Sekunden (Read-only, Anzeige als mm:ss via ConversionFactor) ---
        $remainingIntervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 99999,
                'ConstantActive' => false, 'ConstantValue' => '',
                'ConversionFactor' => 0.016666667,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => true, 'SuffixValue' => ' min',
                'DigitsActive' => true, 'DigitsValue' => 1,
                'IconActive' => true, 'IconValue' => 'clock',
                'ColorActive' => false, 'ColorValue' => 0,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ]
        ]);
        $this->RegisterVariableInteger('RemainingTime', 'Restlaufzeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'hourglass-half',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $remainingIntervals
        ], 101);

        // --- Fehler (Read-only Integer mit Intervallen) ---
        $errorIntervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 1,
                'ConstantActive' => true, 'ConstantValue' => 'Kein Fehler',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'circle-check',
                'ColorActive' => true, 'ColorValue' => 0x00CC00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 1, 'IntervalMaxValue' => 2,
                'ConstantActive' => true, 'ConstantValue' => 'Max. Ventile erreicht',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'triangle-exclamation',
                'ColorActive' => true, 'ColorValue' => 0xFFAA00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 2, 'IntervalMaxValue' => 3,
                'ConstantActive' => true, 'ConstantValue' => 'Nicht verbunden',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Cross',
                'ColorActive' => true, 'ColorValue' => 0xFF4444,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 3, 'IntervalMaxValue' => 5,
                'ConstantActive' => true, 'ConstantValue' => 'Ueberstrom',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'bell',
                'ColorActive' => true, 'ColorValue' => 0xFF4444,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 5, 'IntervalMaxValue' => 6,
                'ConstantActive' => true, 'ConstantValue' => 'Abgebrochen',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'triangle-exclamation',
                'ColorActive' => true, 'ColorValue' => 0xFFAA00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 6, 'IntervalMaxValue' => 7,
                'ConstantActive' => true, 'ConstantValue' => 'Ventil defekt',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'bell',
                'ColorActive' => true, 'ColorValue' => 0xFF4444,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 7, 'IntervalMaxValue' => 8,
                'ConstantActive' => true, 'ConstantValue' => 'Frostschutz',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Snow',
                'ColorActive' => true, 'ColorValue' => 0x2196F3,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 8, 'IntervalMaxValue' => 10,
                'ConstantActive' => true, 'ConstantValue' => 'Hardware-Fehler',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'bell',
                'ColorActive' => true, 'ColorValue' => 0xFF4444,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ]
        ]);

        $this->RegisterVariableInteger('ValveError', 'Fehler', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'triangle-exclamation',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $errorIntervals
        ], 10);

        // --- Bewässerungsdauer (Slider mit EnableAction) ---
        $this->RegisterVariableInteger('WateringDuration', 'Bewaesserungsdauer', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'stopwatch',
            'MIN' => 1.0,
            'MAX' => 240.0,
            'STEP' => 1.0,
            'SUFFIX' => ' min'
        ], 200);
        $this->EnableAction('WateringDuration');

        $this->RegisterVariableInteger('BatteryLevel', 'Batterieladung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'battery-full'
        ], 100);

        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'clock-rotate-left'
        ], 901);
        
        $this->RegisterTimer('CountdownTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "CountdownTimer", "");');

        // --- WateringCommandResult Buffer fuer Fehler-Propagierung ---
        // Wird von Gateway via ForwardData-Response befuellt
    }

    public function Destroy(): void
    {
        parent::Destroy();
        $this->DR_Unregister();
    }


    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if ($this->GetValue('WateringDuration') === 0) {
            $this->SetValueIfChanged('WateringDuration', 30);
        $this->DR_Register('DevicesGenericSensor');
        }

        $deviceID = $this->ReadPropertyString('DeviceID');
        if ($deviceID === '') {
            $this->SetStatus(200);
        } else {
            $this->SetStatus(102);
            $this->SetReceiveDataFilter('');
        }

    }

    public function ReceiveData(string $JSONString): string
    {
        $hash = md5($JSONString);
        if ($this->GetBuffer('LastPayloadHash') === $hash) {
            return "OK";
        }
        $this->SetBuffer('LastPayloadHash', $hash);

        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }

        if (($data['DataID'] ?? '') !== '{FE3A29C6-B712-4D85-9C3E-71A5F82DB430}') {
            return '';
        }

        $myDeviceID = $this->ReadPropertyString('DeviceID');
        if (($data['DeviceID'] ?? '') !== $myDeviceID) {
            return '';
        }

        $myValveID = $this->ReadPropertyString('ValveID');
        if ($myValveID !== '' && ($data['ServiceID'] ?? '') !== $myValveID) {
            return '';
        }

        $this->DA_SetAvailable(true);

        // Gateway sendet: {DataID, DeviceID, ServiceType, Attributes}
        $attributes = $data['Attributes'] ?? [];

        if (isset($attributes['activity']['value'])) {
            $activity = $this->MapValveActivity((string)$attributes['activity']['value']);
            $this->SetValueIfChanged('ValveActivity', $activity);
            $this->SetValueIfChanged('Watering', ($activity === 1 || $activity === 2));
            
            if ($activity === 1 || $activity === 2) {
                $this->SetTimerInterval('CountdownTimer', 1000);
            } else {
                $this->SetTimerInterval('CountdownTimer', 0);
                // WICHTIG: RemainingTime sofort auf 0 setzen wenn Ventil geschlossen!
                // Sonst denkt SmartLawnAI durch den RemainingSeconds-Fallback,
                // das Ventil waere noch offen (Race Condition).
                $this->SetValueIfChanged('RemainingTime', 0);
            }
        }

        if (isset($attributes['duration']['value'])) {
            $this->SetValueIfChanged('RemainingTime', (int)$attributes['duration']['value']);
        }

        if (isset($attributes['error']['value'])) {
            $errorStr = (string)$attributes['error']['value'];
            $error = $this->MapValveError($errorStr);
            $this->SetValueIfChanged('ValveError', $error);

            // Kritische Fehler loggen
            if (in_array($errorStr, ['VALVE_BROKEN', 'FROST_PREVENTS_STARTING', 'LOW_BATTERY_PREVENTS_STARTING', 'VALVE_POWER_SUPPLY_FAILED'])) {
                $this->SendDebug('ValveError', "Kritischer Ventil-Fehler: " . $errorStr, 0);
            }
        }
        
        if (isset($attributes['batteryLevel']['value'])) {
            $this->SetValueIfChanged('BatteryLevel', (int)$attributes['batteryLevel']['value']);
        }

        $this->SetValueIfChanged('LastUpdate', date('d.m.Y H:i:s'));

        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {


            case 'CountdownTimer':
                $remaining = $this->GetValue('RemainingTime');
                if ($remaining > 1) {
                    $this->SetValueIfChanged('RemainingTime', $remaining - 1);
                } else {
                    // Countdown abgelaufen - Status zuruecksetzen
                    $this->SetValueIfChanged('RemainingTime', 0);
                    $this->SetValueIfChanged('Watering', false);
                    $this->SetValueIfChanged('ValveActivity', 0);
                    $this->SetTimerInterval('CountdownTimer', 0);
                }
                break;

            case 'Watering':
                if ($Value) {
                    $this->StartWatering(0);
                } else {
                    $this->StopWatering();
                }
                break;

            case 'WateringDuration':
                $this->SetValueIfChanged('WateringDuration', (int)$Value);
                break;

            default:
                throw new Exception('Ungueltige Aktion: ' . $Ident);
        }
    }

    public function StartWatering(int $durationMinutes = 0): void
    {
        if ($durationMinutes === 0) {
            $durationMinutes = (int)$this->GetValue('WateringDuration');
        }

        $deviceID = $this->ReadPropertyString('DeviceID');
        $valveID = $this->ReadPropertyString('ValveID');
        $serviceID = $valveID !== '' ? $deviceID . ':' . $valveID : $deviceID;

        $body = json_encode([
            'data' => [
                'type' => 'VALVE_CONTROL',
                'attributes' => [
                    'command' => 'START_SECONDS_TO_OVERRIDE',
                    'seconds' => $durationMinutes * 60
                ],
                'id' => 'request-1'
            ]
        ]);

        $response = $this->SendDataToParent(json_encode([
            'DataID' => '{2C4A6B8D-F1E3-4A5C-9B7D-3E5F1A7C9B2D}',
            'Command' => 'SendCommand',
            'ServiceID' => $serviceID,
            'Body' => $body
        ]));

        // Gateway-Antwort pruefen (Fehler-Propagierung an SmartLawnAI)
        $result = @json_decode($response, true);
        if (isset($result['error']) && $result['error'] === true) {
            $reason = $result['message'] ?? 'Unbekannter Gateway-Fehler';
            $this->SLogError("Bewaesserung konnte nicht gestartet werden: {$reason}");
            throw new Exception('Gateway-Fehler: ' . $reason);
        }

        $this->SLogInfo("Bewaesserung gestartet fuer {$durationMinutes} Minuten (Service: {$serviceID})");
    }

    public function StopWatering(): void
    {
        $deviceID = $this->ReadPropertyString('DeviceID');
        $valveID = $this->ReadPropertyString('ValveID');
        $serviceID = $valveID !== '' ? $deviceID . ':' . $valveID : $deviceID;

        $body = json_encode([
            'data' => [
                'type' => 'VALVE_CONTROL',
                'attributes' => [
                    'command' => 'STOP_UNTIL_NEXT_TASK'
                ],
                'id' => 'request-2'
            ]
        ]);

        $response = $this->SendDataToParent(json_encode([
            'DataID' => '{2C4A6B8D-F1E3-4A5C-9B7D-3E5F1A7C9B2D}',
            'Command' => 'SendCommand',
            'ServiceID' => $serviceID,
            'Body' => $body
        ]));

        // Gateway-Antwort pruefen
        $result = @json_decode($response, true);
        if (isset($result['error']) && $result['error'] === true) {
            $reason = $result['message'] ?? 'Unbekannter Gateway-Fehler';
            $this->SLogError("Stopp-Befehl fehlgeschlagen: {$reason}");
            throw new Exception('Gateway-Fehler: ' . $reason);
        }

        $this->SLogInfo("Bewaesserung gestoppt (Service: {$serviceID})");
    }

    private function MapValveActivity(string $activity): int
    {
        return match ($activity) {
            'CLOSED' => 0,
            'MANUAL_WATERING' => 1,
            'SCHEDULED_WATERING' => 2,
            'OFF', 'PAUSED' => 3,
            default => 0,
        };
    }

    private function MapValveError(string $error): int
    {
        return match ($error) {
            'NO_MESSAGE' => 0,
            'CONCURRENT_LIMIT_REACHED' => 1,
            'NOT_CONNECTED' => 2,
            'VALVE_CURRENT_MAX_EXCEEDED' => 3,
            'TOTAL_CURRENT_MAX_EXCEEDED' => 4,
            'WATERING_CANCELED' => 5,
            'VALVE_BROKEN' => 6,
            'FROST_PREVENTS_STARTING' => 7,
            'LOW_BATTERY_PREVENTS_STARTING' => 8,
            'VALVE_POWER_SUPPLY_FAILED' => 9,
            default => 0,
        };
    }



    protected function SetValueIfChanged(string $ident, mixed $value): bool
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
            return true;
        }
        return false;
    }
}
