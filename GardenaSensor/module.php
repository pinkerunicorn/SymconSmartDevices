<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class GardenaSensor extends IPSModuleStrict
{
    use DeviceAvailability_Trait;
    use SmartLog_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');

        $this->DA_RegisterAvailability(900);
        $this->DA_RegisterWatchdog();

        $this->RegisterVariableFloat('SoilMoisture', 'Bodenfeuchte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'Drops'
        ], 1);

        $this->RegisterVariableFloat('SoilTemperature', 'Bodentemperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' °C',
            'ICON' => 'Temperature'
        ], 2);

        $this->RegisterVariableFloat('AmbientTemperature', 'Umgebungstemperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' °C',
            'ICON' => 'Temperature'
        ], 3);

        $this->RegisterVariableInteger('LightIntensity', 'Lichtintensität', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' lux',
            'ICON' => 'Sun'
        ], 4);

        $this->RegisterVariableInteger('BatteryLevel', 'Batteriestand', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'Battery'
        ], 10);

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
        ], 11);

        $this->RegisterVariableInteger('RFLinkLevel', 'Funkverbindung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'Wireless'
        ], 12);

        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Clock'
        ], 901);
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
            // Filter: nur Events für unsere DeviceID durchlassen
            $this->SetReceiveDataFilter('.*"DeviceID":"' . preg_quote($deviceID) . '".*');
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }

        if (($data['DataID'] ?? '') !== '{9A1B3C5D-E7F2-4D6B-8A4C-1F3E5D7B9A2C}') {
            return '';
        }

        // Prüfe ob die DeviceID zu uns passt
        $myDeviceID = $this->ReadPropertyString('DeviceID');
        if (($data['DeviceID'] ?? '') !== $myDeviceID) {
            return '';
        }

        $this->DA_ResetWatchdog(3600);
        $this->DA_SetAvailable(true);

        // Gateway sendet: {DataID, DeviceID, ServiceType, Attributes}
        $attributes = $data['Attributes'] ?? [];

        if (isset($attributes['soilHumidity'])) {
            $this->SetValue('SoilMoisture', (float)$attributes['soilHumidity']);
        }
        if (isset($attributes['soilTemperature'])) {
            $this->SetValue('SoilTemperature', (float)$attributes['soilTemperature']);
        }
        if (isset($attributes['ambientTemperature'])) {
            $this->SetValue('AmbientTemperature', (float)$attributes['ambientTemperature']);
        }
        if (isset($attributes['lightIntensity'])) {
            $this->SetValue('LightIntensity', (int)$attributes['lightIntensity']);
        }
        if (isset($attributes['batteryLevel'])) {
            $this->SetValue('BatteryLevel', (int)$attributes['batteryLevel']);
        }
        if (isset($attributes['batteryState'])) {
            $this->SetValue('BatteryStatus', $this->MapBatteryStatus((string)$attributes['batteryState']));
        }
        if (isset($attributes['rfLinkLevel'])) {
            $this->SetValue('RFLinkLevel', (int)$attributes['rfLinkLevel']);
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
            default:
                throw new Exception('Ungültige Aktion: ' . $Ident);
        }
    }

    private function MapBatteryStatus(string $status): int
    {
        switch (strtoupper($status)) {
            case 'OK':
                return 0;
            case 'LOW':
                return 1;
            case 'REPLACE_NOW':
                return 2;
            case 'OUT_OF_OPERATION':
                return 3;
            case 'CHARGING':
                return 4;
            case 'NO_BATTERY':
                return 5;
            case 'UNKNOWN':
            default:
                return 6;
        }
    }
}
