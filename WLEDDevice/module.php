<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/WLEDConstants.php';

class WLEDDevice extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterWatchdog();
        $this->DA_RegisterAvailability(900); // Alarm priority: 0 (Low)

        // Properties
        $this->RegisterPropertyBoolean('ShowEffects', true);
        $this->RegisterPropertyBoolean('ShowPalettes', false);
        $this->RegisterPropertyBoolean('ShowPresets', true);
        $this->RegisterPropertyBoolean('ShowWhiteChannel', false);
        $this->RegisterPropertyBoolean('ShowCCT', false);
        $this->RegisterPropertyBoolean('ShowDiagnostics', false);
        $this->RegisterPropertyFloat('DefaultTransition', 0.7);
        $this->RegisterPropertyInteger('AutoReconnectInterval', 30);

        // Attributes for Caching
        $this->RegisterAttributeString('CachedEffects', '[]');
        $this->RegisterAttributeString('CachedPalettes', '[]');
        $this->RegisterAttributeString('CachedPresets', '[]');

        // Permanent Variables
        $this->RegisterVariableBoolean('Power', 'Power', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Power'
        ], 1);
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('Brightness', 'Helligkeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Sun',
            'MIN' => 0.0,
            'MAX' => 100.0,
            'STEP_SIZE' => 1.0,
            'SUFFIX' => ' %',
            'PERCENTAGE' => true
        ], 5);
        $this->EnableAction('Brightness');

        $this->RegisterVariableInteger('Color', 'Farbe', [
            'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
            'ENCODING' => 0
        ], 10);
        $this->EnableAction('Color');

        $this->RegisterTimer('ReconnectTimer', 0, 'WLED_Reconnect($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StateRefreshTimer', 0, 'WLED_RequestFullState($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'require',
            'modules' => [
                ['moduleID' => '{D68FD31F-0E90-7019-F16C-1949BD3079EF}']
            ]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $this->RegisterOptionalVariables();

        $interval = $this->ReadPropertyInteger('AutoReconnectInterval');
        $this->SetTimerInterval('ReconnectTimer', $interval * 1000);

        // Timer for initial state fetch (delayed to not block apply changes)
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SetTimerInterval('StateRefreshTimer', 2000);
        }

        $this->updateControlState();
    }

    private function RegisterOptionalVariables(): void
    {
        if ($this->ReadPropertyBoolean('ShowWhiteChannel')) {
            $this->RegisterVariableInteger('WhiteChannel', 'Wei\u00dfkanal', [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => 0.0,
                'MAX' => 100.0,
                'STEP_SIZE' => 1.0,
                'SUFFIX' => ' %',
                'PERCENTAGE' => true
            ], 15);
            $this->EnableAction('WhiteChannel');
        } else {
            $this->UnregisterVariableIfExists('WhiteChannel');
        }

        if ($this->ReadPropertyBoolean('ShowEffects')) {
            $this->BuildEffectEnumeration();
            $this->RegisterVariableInteger('EffectSpeed', 'Geschwindigkeit', [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON' => 'Motion',
                'MIN' => 0.0,
                'MAX' => 100.0,
                'STEP_SIZE' => 1.0,
                'SUFFIX' => ' %',
                'PERCENTAGE' => true
            ], 21);
            $this->RegisterVariableInteger('EffectIntensity', 'Intensit\u00e4t', [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON' => 'Intensity',
                'MIN' => 0.0,
                'MAX' => 100.0,
                'STEP_SIZE' => 1.0,
                'SUFFIX' => ' %',
                'PERCENTAGE' => true
            ], 22);
            $this->EnableAction('EffectSpeed');
            $this->EnableAction('EffectIntensity');
        } else {
            $this->UnregisterVariableIfExists('Effect');
            $this->UnregisterVariableIfExists('EffectSpeed');
            $this->UnregisterVariableIfExists('EffectIntensity');
        }

        if ($this->ReadPropertyBoolean('ShowPalettes')) {
            $this->BuildPaletteEnumeration();
        } else {
            $this->UnregisterVariableIfExists('Palette');
        }

        if ($this->ReadPropertyBoolean('ShowPresets')) {
            $this->BuildPresetEnumeration();
        } else {
            $this->UnregisterVariableIfExists('Preset');
        }

        if ($this->ReadPropertyBoolean('ShowDiagnostics')) {
            $this->RegisterVariableInteger('WifiSignal', 'WiFi Signal', [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON' => 'Network',
                'SUFFIX' => ' %'
            ], 910);
            $this->RegisterVariableString('FirmwareVersion', 'Firmware', [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON' => 'Information'
            ], 911);
            $this->RegisterVariableInteger('PowerConsumption', 'Stromverbrauch', [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON' => 'Electricity',
                'SUFFIX' => ' mA'
            ], 912);
        } else {
            $this->UnregisterVariableIfExists('WifiSignal');
            $this->UnregisterVariableIfExists('FirmwareVersion');
            $this->UnregisterVariableIfExists('PowerConsumption');
        }
    }

    private function UnregisterVariableIfExists(string $ident): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            $this->UnregisterVariable($ident);
        }
    }

    private function getHost(): string
    {
        $parent = $this->GetParentID();
        if ($parent <= 0) return '';
        
        $url = (string)@IPS_GetProperty($parent, 'URL');
        if ($url === '') return '';
        
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private function fetchHttpData(string $path): array
    {
        $host = $this->getHost();
        if ($host === '') return [];

        $jsonData = @file_get_contents(
            sprintf('http://%s%s', $host, $path),
            false,
            stream_context_create([
                'http' => ['timeout' => 2]
            ])
        );

        if ($jsonData === false) return [];

        try {
            $decoded = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function BuildEffectEnumeration(): void
    {
        $cachedStr = $this->ReadAttributeString('CachedEffects');
        $cached = json_decode($cachedStr, true);

        if (empty($cached)) {
            $cached = $this->fetchHttpData(WLEDConstants::API_EFFECTS);
            if (!empty($cached)) {
                $this->WriteAttributeString('CachedEffects', json_encode($cached));
            }
        }

        $options = [];
        if (is_array($cached)) {
            foreach ($cached as $index => $label) {
                if (!is_numeric((string)$index)) continue;
                $options[] = [
                    'Value' => (int)$index,
                    'Caption' => (string)$label,
                    'IconActive' => false,
                    'IconValue' => '',
                    'Color' => -1
                ];
            }
        }

        if (empty($options)) {
            $options[] = ['Value' => 0, 'Caption' => 'Solid', 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }

        $this->RegisterVariableInteger('Effect', 'Effekt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON' => 'Star',
            'OPTIONS' => json_encode($options)
        ], 20);
        $this->EnableAction('Effect');
    }

    private function BuildPaletteEnumeration(): void
    {
        $cachedStr = $this->ReadAttributeString('CachedPalettes');
        $cached = json_decode($cachedStr, true);

        if (empty($cached)) {
            $cached = $this->fetchHttpData(WLEDConstants::API_PALETTES);
            if (!empty($cached)) {
                $this->WriteAttributeString('CachedPalettes', json_encode($cached));
            }
        }

        $options = [];
        if (is_array($cached)) {
            foreach ($cached as $index => $label) {
                if (!is_numeric((string)$index)) continue;
                $options[] = [
                    'Value' => (int)$index,
                    'Caption' => (string)$label,
                    'IconActive' => false,
                    'IconValue' => '',
                    'Color' => -1
                ];
            }
        }

        if (empty($options)) {
            $options[] = ['Value' => 0, 'Caption' => 'Default', 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }

        $this->RegisterVariableInteger('Palette', 'Palette', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON' => 'Paintbrush',
            'OPTIONS' => json_encode($options)
        ], 25);
        $this->EnableAction('Palette');
    }

    private function BuildPresetEnumeration(): void
    {
        $cachedStr = $this->ReadAttributeString('CachedPresets');
        $cached = json_decode($cachedStr, true);

        if (empty($cached)) {
            $cached = $this->fetchHttpData(WLEDConstants::API_PRESETS);
            if (!empty($cached)) {
                $this->WriteAttributeString('CachedPresets', json_encode($cached));
            }
        }

        $options = [['Value' => 0, 'Caption' => '- keine -', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]];
        if (is_array($cached)) {
            foreach ($cached as $key => $preset) {
                if (!is_numeric((string)$key) || !is_array($preset)) continue;
                if (!isset($preset['n'])) continue;
                
                // Ignoriere Playlists wenn wir sie nicht getrennt anzeigen
                // (Oder wir zeigen sie hier mit an, WLED unterscheidet sie primär an pl/ps, aber ps feuert beides)
                
                $options[] = [
                    'Value' => (int)$key,
                    'Caption' => (string)$preset['n'],
                    'IconActive' => false,
                    'IconValue' => '',
                    'Color' => -1
                ];
            }
        }

        $this->RegisterVariableInteger('Preset', 'Preset', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON' => 'Heart',
            'OPTIONS' => json_encode($options)
        ], 30);
        $this->EnableAction('Preset');
    }

    public function RefreshLists(): void
    {
        $this->WriteAttributeString('CachedEffects', '[]');
        $this->WriteAttributeString('CachedPalettes', '[]');
        $this->WriteAttributeString('CachedPresets', '[]');
        $this->ApplyChanges();
        $this->SLogInfo('Listen aktualisiert.');
    }

    public function RequestFullState(): void
    {
        $this->SetTimerInterval('StateRefreshTimer', 0);
        $this->sendToWLED(['v' => true]);
    }

    public function Reconnect(): void
    {
        $parentID = $this->GetParentID();
        if ($parentID > 0) {
            $parentConfig = json_decode(IPS_GetConfiguration($parentID), true);
            $propName = isset($parentConfig['Active']) ? 'Active' : (isset($parentConfig['Open']) ? 'Open' : '');
            if ($propName !== '' && IPS_GetProperty($parentID, $propName)) {
                $this->SLogInfo("Verbindung getrennt. Versuche Reconnect...");
                @IPS_SetProperty($parentID, $propName, false);
                @IPS_ApplyChanges($parentID);
                @IPS_SetProperty($parentID, $propName, true);
                @IPS_ApplyChanges($parentID);
            }
        }
    }

    private function GetParentID(): int
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        return ($instance && isset($instance['ConnectionID'])) ? $instance['ConnectionID'] : 0;
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->DA_SetAvailable(true);
        $this->DA_ResetWatchdog(WLEDConstants::WATCHDOG_TIMEOUT);

        switch ($Ident) {
            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                break;
            case 'Power':
                $this->sendToWLED(['on' => $Value, 'transition' => $this->getTransitionTicks()]);
                // Optimistic update
                $this->SetValue('Power', $Value);
                $this->updateControlState();
                break;
            case 'Brightness':
                $raw = (int)round($Value * 2.55);
                $this->sendToWLED(['bri' => $raw, 'transition' => $this->getTransitionTicks()]);
                // Optimistic update
                $this->SetValue('Brightness', $Value);
                if ($Value > 0 && !$this->GetValue('Power')) {
                    $this->SetValue('Power', true);
                } elseif ($Value == 0 && $this->GetValue('Power')) {
                    $this->SetValue('Power', false);
                }
                $this->updateControlState();
                break;
            case 'Color':
                $rgb = $this->HexToRGB((int)$Value);
                $this->sendToWLED(['seg' => [['id' => 0, 'col' => [$rgb]]]]);
                $this->SetValue('Color', $Value);
                break;
            case 'WhiteChannel':
                $rgb = $this->HexToRGB($this->GetValue('Color'));
                $rgb[3] = (int)round($Value * 2.55);
                $this->sendToWLED(['seg' => [['id' => 0, 'col' => [$rgb]]]]);
                $this->SetValue('WhiteChannel', $Value);
                break;
            case 'Effect':
                $this->sendToWLED(['seg' => [['id' => 0, 'fx' => $Value]]]);
                $this->SetValue('Effect', $Value);
                $this->updateControlState();
                break;
            case 'EffectSpeed':
                $this->sendToWLED(['seg' => [['id' => 0, 'sx' => (int)round($Value * 2.55)]]]);
                $this->SetValue('EffectSpeed', $Value);
                break;
            case 'EffectIntensity':
                $this->sendToWLED(['seg' => [['id' => 0, 'ix' => (int)round($Value * 2.55)]]]);
                $this->SetValue('EffectIntensity', $Value);
                break;
            case 'Palette':
                $this->sendToWLED(['seg' => [['id' => 0, 'pal' => $Value]]]);
                $this->SetValue('Palette', $Value);
                break;
            case 'Preset':
                $this->sendToWLED(['ps' => $Value]);
                $this->SetValue('Preset', $Value);
                break;
            default:
                throw new Exception("Invalid Action: " . $Ident);
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        $this->DA_ResetWatchdog(WLEDConstants::WATCHDOG_TIMEOUT);
        $this->DA_SetAvailable(true);

        if ($data['DataID'] != '{018EF6B5-AB94-40C6-AA53-46943E824ACF}') {
            return '';
        }

        $bufferRaw = trim($data['Buffer'] ?? '');
        $buffer = ctype_xdigit($bufferRaw) ? hex2bin($bufferRaw) : $bufferRaw;

        try {
            $json = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);

            if (isset($json['state'])) {
                $this->processState($json['state']);
            }
            if (isset($json['info'])) {
                $this->processInfo($json['info']);
            }
        } catch (Throwable $e) {
            $this->SLogWarning("ReceiveData Error: " . $e->getMessage());
        }
        
        return '';
    }

    private function processState(array $state): void
    {
        $needsUiUpdate = false;

        if (isset($state['on'])) {
            $this->SetValue('Power', $state['on']);
            $needsUiUpdate = true;
        }
        if (isset($state['bri'])) {
            $this->SetValue('Brightness', (int)round($state['bri'] / 2.55));
            if ($state['bri'] == 0 && $this->GetValue('Power')) {
                $this->SetValue('Power', false);
                $needsUiUpdate = true;
            }
        }
        if (isset($state['ps'])) {
            $this->setSafeValue('Preset', $state['ps']);
        }

        if (isset($state['seg']) && is_array($state['seg']) && count($state['seg']) > 0) {
            // We currently only care about segment 0 (Smart Mode)
            $seg = $state['seg'][0];
            
            if (isset($seg['col']) && is_array($seg['col']) && count($seg['col']) > 0) {
                $c = $seg['col'][0];
                if (is_array($c) && count($c) >= 3) {
                    $this->SetValue('Color', $this->RGBToHex($c));
                    if (count($c) >= 4) {
                        $this->setSafeValue('WhiteChannel', (int)round($c[3] / 2.55));
                    }
                }
            }
            if (isset($seg['fx'])) {
                $this->setSafeValue('Effect', $seg['fx']);
                $needsUiUpdate = true;
            }
            if (isset($seg['sx'])) {
                $this->setSafeValue('EffectSpeed', (int)round($seg['sx'] / 2.55));
            }
            if (isset($seg['ix'])) {
                $this->setSafeValue('EffectIntensity', (int)round($seg['ix'] / 2.55));
            }
            if (isset($seg['pal'])) {
                $this->setSafeValue('Palette', $seg['pal']);
            }
        }

        if ($needsUiUpdate) {
            $this->updateControlState();
        }
    }

    private function processInfo(array $info): void
    {
        if (isset($info['wifi']['signal'])) {
            $this->setSafeValue('WifiSignal', $info['wifi']['signal']);
        }
        if (isset($info['ver'])) {
            $this->setSafeValue('FirmwareVersion', $info['ver']);
        }
        if (isset($info['leds']['pwr'])) {
            $this->setSafeValue('PowerConsumption', $info['leds']['pwr']);
        }
    }

    private function updateControlState(): void
    {
        $powerOff = !$this->GetValue('Power');
        
        $this->setDisabledSafe('Brightness', $powerOff);
        $this->setDisabledSafe('Color', $powerOff);
        $this->setDisabledSafe('WhiteChannel', $powerOff);

        $effectId = $this->getSafeValue('Effect', 0);
        $effectSolid = ($effectId === WLEDConstants::EFFECT_SOLID);
        
        $this->setDisabledSafe('EffectSpeed', $powerOff || $effectSolid);
        $this->setDisabledSafe('EffectIntensity', $powerOff || $effectSolid);
        $this->setDisabledSafe('Palette', $powerOff || $effectSolid);
    }

    private function setDisabledSafe(string $ident, bool $disabled): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            IPS_SetDisabled($id, $disabled);
        }
    }

    private function setSafeValue(string $ident, mixed $value): void
    {
        if (@$this->GetIDForIdent($ident) > 0) {
            $this->SetValue($ident, $value);
        }
    }

    private function getSafeValue(string $ident, mixed $default): mixed
    {
        if (@$this->GetIDForIdent($ident) > 0) {
            return $this->GetValue($ident);
        }
        return $default;
    }

    private function getTransitionTicks(): int
    {
        return (int)round($this->ReadPropertyFloat('DefaultTransition') * 10);
    }

    private function sendToWLED(array $payload): void
    {
        $parent = $this->GetParentID();
        if ($parent > 0 && function_exists('WSC_SendMessage')) {
            WSC_SendMessage($parent, json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }

    private function HexToRGB(int $hexInt): array
    {
        $r = floor($hexInt / 65536);
        $g = floor(($hexInt - ($r * 65536)) / 256);
        $b = $hexInt - ($g * 256) - ($r * 65536);
        return [(int)$r, (int)$g, (int)$b];
    }

    private function RGBToHex(array $rgb): int
    {
        return $rgb[0] * 256 * 256 + $rgb[1] * 256 + $rgb[2];
    }
}
