<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class GardenaIrrigationControl extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');

        $this->RegisterVariableInteger('BatteryLevel', 'Batteriestand', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'Battery'
        ], 1);

        $batteryIntervals = json_encode([
            [ 'IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'OK', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Battery', 'ColorActive' => true, 'ColorValue' => 0x00FF00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'LOW', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Battery', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'REPLACE_NOW', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 3, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'OUT_OF_OPERATION', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Cross', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 4, 'IntervalMaxValue' => 4, 'ConstantActive' => true, 'ConstantValue' => 'CHARGING', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Lightning', 'ColorActive' => true, 'ColorValue' => 0x0000FF, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 5, 'IntervalMaxValue' => 5, 'ConstantActive' => true, 'ConstantValue' => 'NO_BATTERY', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Plug', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 6, 'IntervalMaxValue' => 6, 'ConstantActive' => true, 'ConstantValue' => 'UNKNOWN', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Help', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ]
        ]);

        $this->RegisterVariableInteger('BatteryStatus', 'Batteriestatus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Battery',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $batteryIntervals
        ], 2);

        $this->RegisterVariableInteger('RFLinkLevel', 'Funkverbindung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'Wireless'
        ], 3);

        $masterValveIntervals = json_encode([
            [ 'IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'OK', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Tap', 'ColorActive' => true, 'ColorValue' => 0x00FF00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Warnung', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Tap', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Fehler', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Tap', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ]
        ]);

        $this->RegisterVariableInteger('MasterValveState', 'Hauptventil Status', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Tap',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $masterValveIntervals
        ], 4);

        $errorIntervals = json_encode([
            [ 'IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'Kein Fehler', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0x00FF00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Max. Ventile erreicht', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Nicht verbunden', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 3, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Stromlimit überschritten', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 4, 'IntervalMaxValue' => 4, 'ConstantActive' => true, 'ConstantValue' => 'Abgebrochen', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 5, 'IntervalMaxValue' => 5, 'ConstantActive' => true, 'ConstantValue' => 'Defekt', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 6, 'IntervalMaxValue' => 6, 'ConstantActive' => true, 'ConstantValue' => 'Frostwarnung', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 7, 'IntervalMaxValue' => 7, 'ConstantActive' => true, 'ConstantValue' => 'Batterie schwach', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 8, 'IntervalMaxValue' => 8, 'ConstantActive' => true, 'ConstantValue' => 'Stromausfall', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 9, 'IntervalMaxValue' => 9, 'ConstantActive' => true, 'ConstantValue' => 'Unbekannt', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ]
        ]);

        $this->RegisterVariableInteger('LastErrorCode', 'Letzter Fehler', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Warning',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $errorIntervals
        ], 5);

        $this->DA_RegisterWatchdog();
        $this->DA_RegisterAvailability();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $deviceID = $this->ReadPropertyString('DeviceID');
        if (empty($deviceID)) {
            $this->SetStatus(200);
        } else {
            $this->SetStatus(102);
            $this->SetReceiveDataFilter('.*"DeviceID":"' . preg_quote($deviceID) . '".*');
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

        $this->DA_ResetWatchdog(3600);
        $this->DA_SetAvailable(true);

        $serviceType = $data['ServiceType'] ?? '';
        $attributes = $data['Attributes'] ?? [];

        if ($serviceType === 'COMMON') {
            if (isset($attributes['batteryLevel']['value'])) {
                $this->SetValue('BatteryLevel', (int)$attributes['batteryLevel']['value']);
            }
            if (isset($attributes['batteryState']['value'])) {
                $this->SetValue('BatteryStatus', $this->MapBatteryStatus((string)$attributes['batteryState']['value']));
            }
            if (isset($attributes['rfLinkLevel']['value'])) {
                $this->SetValue('RFLinkLevel', (int)$attributes['rfLinkLevel']['value']);
            }
        } elseif ($serviceType === 'VALVE_SET') {
            if (isset($attributes['state']['value'])) {
                $this->SetValue('MasterValveState', $this->MapMasterValveState((string)$attributes['state']['value']));
            }
            if (isset($attributes['lastErrorCode']['value'])) {
                $this->SetValue('LastErrorCode', $this->MapLastErrorCode((string)$attributes['lastErrorCode']['value']));
            }
        }

        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                break;
            default:
                throw new Exception('Ungültige Aktion: ' . $Ident);
        }
    }

    private function MapBatteryStatus(string $status): int
    {
        switch (strtoupper($status)) {
            case 'OK': return 0;
            case 'LOW': return 1;
            case 'REPLACE_NOW': return 2;
            case 'OUT_OF_OPERATION': return 3;
            case 'CHARGING': return 4;
            case 'NO_BATTERY': return 5;
            case 'UNKNOWN':
            default:
                return 6;
        }
    }

    private function MapMasterValveState(string $state): int
    {
        switch (strtoupper($state)) {
            case 'OK': return 0;
            case 'WARNING': return 1;
            case 'ERROR': return 2;
            default: return 0;
        }
    }

    private function MapLastErrorCode(string $code): int
    {
        switch (strtoupper($code)) {
            case 'NO_MESSAGE': return 0;
            case 'MAX_VALVES': return 1;
            case 'NOT_CONNECTED': return 2;
            case 'CURRENT_LIMIT': return 3;
            case 'CANCELLED': return 4;
            case 'DEFECT': return 5;
            case 'FROST_WARNING': return 6;
            case 'BATTERY_LOW': return 7;
            case 'POWER_OUTAGE': return 8;
            case 'UNKNOWN':
            default:
                return 9;
        }
    }
}
