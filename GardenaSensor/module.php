<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class GardenaSensor extends IPSModuleStrict
{
    use DeviceAvailability_Trait;
    use SmartLog_Trait;
    use DeviceRegistration_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');

        $this->DA_RegisterAvailability(900);
        $this->DA_RegisterWatchdog();

        $this->RegisterVariableInteger('SoilMoisture', 'Bodenfeuchte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'droplet'
        ], 1);

        $this->RegisterVariableFloat('SoilTemperature', 'Bodentemperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' °C',
            'ICON' => 'temperature-half',
            'DIGITS' => 1
        ], 2);

        $this->RegisterVariableFloat('AmbientTemperature', 'Lufttemperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' °C',
            'ICON' => 'temperature-half',
            'DIGITS' => 1
        ], 3);

        $this->RegisterVariableInteger('LightIntensity', 'Lichtstaerke', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' Lux',
            'ICON' => 'sun'
        ], 4);

        $batteryIntervals = json_encode([
            [ 'IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'OK', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'battery-full', 'ColorActive' => true, 'ColorValue' => 0x00FF00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'LOW', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'battery-full', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'REPLACE_NOW', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 3, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'OUT_OF_OPERATION', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Cross', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 4, 'IntervalMaxValue' => 4, 'ConstantActive' => true, 'ConstantValue' => 'CHARGING', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Lightning', 'ColorActive' => true, 'ColorValue' => 0x0000FF, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 5, 'IntervalMaxValue' => 5, 'ConstantActive' => true, 'ConstantValue' => 'NO_BATTERY', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'plug', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
            [ 'IntervalMinValue' => 6, 'IntervalMaxValue' => 6, 'ConstantActive' => true, 'ConstantValue' => 'UNKNOWN', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Help', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ]
        ]);
        
        $this->RegisterVariableInteger('BatteryLevel', 'Batterieladung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'battery-full'
        ], 100);

        $this->RegisterVariableInteger('BatteryStatus', 'Batteriestatus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'battery-full',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $batteryIntervals
        ], 101);

        $this->RegisterVariableInteger('RFLinkLevel', 'Funkverbindung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' %',
            'ICON' => 'wifi'
        ], 102);

        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'clock-rotate-left'
        ], 901);
        $this->DR_Register('DevicesGenericSensor');
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

        $deviceID = $this->ReadPropertyString('DeviceID');
        if (empty($deviceID)) {
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

        // Prüfe ob die DeviceID zu uns passt
        $myDeviceID = $this->ReadPropertyString('DeviceID');
        if (($data['DeviceID'] ?? '') !== $myDeviceID) {
            return '';
        }

        $this->DA_ResetWatchdog(14400); // 4h - Sensor meldet ca. alle 1h
        $this->DA_SetAvailable(true);

        // Gateway sendet: {DataID, DeviceID, ServiceType, Attributes}
        $attributes = $data['Attributes'] ?? [];

        if (isset($attributes['soilHumidity']['value'])) {
            $this->SetValue('SoilMoisture', (int)$attributes['soilHumidity']['value']);
        }
        if (isset($attributes['soilTemperature']['value'])) {
            $this->SetValue('SoilTemperature', (float)$attributes['soilTemperature']['value']);
        }
        if (isset($attributes['ambientTemperature']['value'])) {
            $this->SetValue('AmbientTemperature', (float)$attributes['ambientTemperature']['value']);
        }
        if (isset($attributes['lightIntensity']['value'])) {
            $this->SetValue('LightIntensity', (int)$attributes['lightIntensity']['value']);
        }

        if (isset($attributes['batteryState']['value'])) {
            $this->SetValue('BatteryStatus', $this->MapBatteryStatus((string)$attributes['batteryState']['value']));
        }
        
        if (isset($attributes['batteryLevel']['value'])) {
            $this->SetValue('BatteryLevel', (int)$attributes['batteryLevel']['value']);
        }
        if (isset($attributes['rfLinkLevel']['value'])) {
            $this->SetValue('RFLinkLevel', (int)$attributes['rfLinkLevel']['value']);
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
