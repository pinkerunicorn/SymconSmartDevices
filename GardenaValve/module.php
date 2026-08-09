<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class GardenaValve extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('ValveID', '');
        $this->RegisterPropertyInteger('DefaultDuration', 30);

        $this->DA_RegisterAvailability(900);
        $this->DA_RegisterWatchdog();

        // --- Ventilstatus (Read-only Integer mit Intervallen) ---
        $activityIntervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 1,
                'ConstantActive' => true, 'ConstantValue' => 'Geschlossen',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Tap',
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
                'IconActive' => true, 'IconValue' => 'Drops',
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
                'IconActive' => true, 'IconValue' => 'Calendar',
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
            'ICON' => 'Tap',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $activityIntervals
        ], 1);

        // --- Bewässerung (Switch mit EnableAction) ---
        $this->RegisterVariableBoolean('Watering', 'Bewaesserung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Drops'
        ], 2);
        $this->EnableAction('Watering');

        // --- Restlaufzeit (Read-only) ---
        $this->RegisterVariableInteger('RemainingTime', 'Restlaufzeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Clock',
            'SUFFIX' => ' min'
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
                'IconActive' => true, 'IconValue' => 'Ok',
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
                'IconActive' => true, 'IconValue' => 'Warning',
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
                'IconActive' => true, 'IconValue' => 'Alert',
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
                'IconActive' => true, 'IconValue' => 'Warning',
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
                'IconActive' => true, 'IconValue' => 'Alert',
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
                'IconActive' => true, 'IconValue' => 'Alert',
                'ColorActive' => true, 'ColorValue' => 0xFF4444,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ]
        ]);

        $this->RegisterVariableInteger('ValveError', 'Fehler', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Warning',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $errorIntervals
        ], 10);

        // --- Bewässerungsdauer (Slider mit EnableAction) ---
        $this->RegisterVariableInteger('WateringDuration', 'Bewaesserungsdauer', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Clock',
            'MIN' => 1.0,
            'MAX' => 240.0,
            'STEP' => 1.0,
            'SUFFIX' => ' min'
        ], 200);
        $this->EnableAction('WateringDuration');

        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Clock'
        ], 901);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $defaultDuration = $this->ReadPropertyInteger('DefaultDuration');
        if ($this->GetValue('WateringDuration') === 0) {
            $this->SetValue('WateringDuration', $defaultDuration);
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

        $this->DA_ResetWatchdog(3600);
        $this->DA_SetAvailable(true);

        // Gateway sendet: {DataID, DeviceID, ServiceType, Attributes}
        $attributes = $data['Attributes'] ?? [];

        if (isset($attributes['activity']['value'])) {
            $activity = $this->MapValveActivity((string)$attributes['activity']['value']);
            $this->SetValue('ValveActivity', $activity);
            $this->SetValue('Watering', ($activity === 1 || $activity === 2));
        }

        if (isset($attributes['duration']['value'])) {
            $this->SetValue('RemainingTime', (int)round((int)$attributes['duration']['value'] / 60));
        }

        if (isset($attributes['error']['value'])) {
            $errorStr = (string)$attributes['error']['value'];
            $error = $this->MapValveError($errorStr);
            $this->SetValue('ValveError', $error);

            // Kritische Fehler loggen
            if (in_array($errorStr, ['VALVE_BROKEN', 'FROST_PREVENTS_STARTING', 'LOW_BATTERY_PREVENTS_STARTING', 'VALVE_POWER_SUPPLY_FAILED'])) {
                $this->SendDebug('ValveError', "Kritischer Ventil-Fehler: " . $errorStr, 0);
            }
        }

        $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));

        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                break;

            case 'Watering':
                if ($Value) {
                    $this->StartWatering(0);
                } else {
                    $this->StopWatering();
                }
                break;

            case 'WateringDuration':
                $this->SetValue('WateringDuration', (int)$Value);
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

        $this->SendDataToParent(json_encode([
            'DataID' => '{2C4A6B8D-F1E3-4A5C-9B7D-3E5F1A7C9B2D}',
            'Command' => 'SendCommand',
            'ServiceID' => $serviceID,
            'Body' => $body
        ]));

        $this->SetValue('Watering', true);
        $this->SetValue('ValveActivity', 1); // MANUAL_WATERING
        $this->SetValue('RemainingTime', $durationMinutes);

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

        $this->SendDataToParent(json_encode([
            'DataID' => '{2C4A6B8D-F1E3-4A5C-9B7D-3E5F1A7C9B2D}',
            'Command' => 'SendCommand',
            'ServiceID' => $serviceID,
            'Body' => $body
        ]));

        $this->SetValue('Watering', false);
        $this->SetValue('ValveActivity', 0); // CLOSED
        $this->SetValue('RemainingTime', 0);

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

    private function MapBatteryStatus(string $status): int
    {
        return match (strtoupper($status)) {
            'OK' => 0,
            'LOW' => 1,
            'REPLACE_NOW' => 2,
            'OUT_OF_OPERATION' => 3,
            'CHARGING' => 4,
            'NO_BATTERY' => 5,
            default => 6,
        };
    }
}
