<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartFountain extends IPSModuleStrict
{
    use SmartLog_Trait;

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // --- Properties ---
        $this->RegisterPropertyInteger('PumpTargetID', 0);
        $this->RegisterPropertyInteger('PowerMeterID', 0);
        $this->RegisterPropertyInteger('WledDeviceID', 0);
        $this->RegisterPropertyInteger('WledGardenID', 0);
        $this->RegisterPropertyInteger('TwinklyDeviceID', 0);
        $this->RegisterPropertyInteger('SonosDeviceID', 0);
        $this->RegisterPropertyString('SynthBaseUrl', 'http://10.1.60.150:5000');
        $this->RegisterPropertyInteger('ShowDurationSec', 20);

        
        $this->RegisterPropertyInteger('MinPumpPercent', 5);
        $this->RegisterPropertyInteger('MaxPumpPercent', 100);
        $this->RegisterPropertyInteger('SoftStartMs', 200);
        $this->RegisterPropertyInteger('SoftStopMs', 500);
        
        $this->RegisterPropertyInteger('ChoreographyIntervalMs', 100);

        // --- Timers ---
        $this->RegisterTimer('ChoreographyTimer', 0, 'SFTN_Tick($_IPS[\'TARGET\']);');
        
        // Variables will be configured via SetupVariablePresentations in ApplyChanges
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // --- References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        
        $pumpTargetID = $this->ReadPropertyInteger('PumpTargetID');
        if ($pumpTargetID > 1 && @IPS_ObjectExists($pumpTargetID)) {
            $this->RegisterReference($pumpTargetID);
        }

        $powerMeterID = $this->ReadPropertyInteger('PowerMeterID');
        if ($powerMeterID > 1 && @IPS_ObjectExists($powerMeterID)) {
            $this->RegisterReference($powerMeterID);
            $this->RegisterMessage($powerMeterID, VM_UPDATE);
        }

        $switchPres = defined('VARIABLE_PRESENTATION_SWITCH') ? VARIABLE_PRESENTATION_SWITCH : 3;
        $sliderPres = defined('VARIABLE_PRESENTATION_SLIDER') ? VARIABLE_PRESENTATION_SLIDER : 2;
        $valPres = defined('VARIABLE_PRESENTATION_VALUE_PRESENTATION') ? VARIABLE_PRESENTATION_VALUE_PRESENTATION : 1;
        $enumPres = defined('VARIABLE_PRESENTATION_ENUMERATION') ? VARIABLE_PRESENTATION_ENUMERATION : 5;

        // --- Variables ---
        $this->RegisterVariableBoolean('Active', 'Aktiv', [
            'PRESENTATION' => $switchPres,
            'ICON' => 'Power'
        ], 10);
        $this->EnableAction('Active');

        $showModeOptions = json_encode([
            ['Value' => 0, 'Caption' => 'Manuell',     'Color' => 0x888888, 'IconActive' => true, 'IconValue' => 'Gear'],
            ['Value' => 1, 'Caption' => 'Dinner',      'Color' => 0xFF9900, 'IconActive' => true, 'IconValue' => 'Light'],
            ['Value' => 2, 'Caption' => 'Party',       'Color' => 0xFF0066, 'IconActive' => true, 'IconValue' => 'Speaker'],
            ['Value' => 3, 'Caption' => 'Zen',         'Color' => 0x00CCCC, 'IconActive' => true, 'IconValue' => 'Drops'],
            ['Value' => 4, 'Caption' => 'Romantik',    'Color' => 0xFF00FF, 'IconActive' => true, 'IconValue' => 'Heart'],
            ['Value' => 5, 'Caption' => 'Regenbogen',  'Color' => 0x00FF00, 'IconActive' => true, 'IconValue' => 'Sun'],
            ['Value' => 6, 'Caption' => 'Musik-Show',  'Color' => 0x0066FF, 'IconActive' => true, 'IconValue' => 'Melody'],
        ]);

        $this->RegisterVariableInteger('ShowMode', 'Show-Modus', [
            'PRESENTATION' => $enumPres,
            'ICON' => 'Execute',
            'OPTIONS' => $showModeOptions
        ], 25);
        $this->EnableAction('ShowMode');

        $percentPresentation = [
            'PRESENTATION' => $sliderPres,
            'ICON' => 'Intensity',
            'MIN' => 0,
            'MAX' => 100,
            'STEP' => 1,
            'SUFFIX' => ' %'
        ];

        $this->RegisterVariableInteger('PumpSpeed', 'Pumpengeschwindigkeit', $percentPresentation, 20);
        $this->EnableAction('PumpSpeed');

        $choreoOptions = json_encode([
            ['Value' => 0, 'Caption' => 'Manuell', 'Color' => 0x000000, 'IconActive' => true, 'IconValue' => 'Gear'],
            ['Value' => 1, 'Caption' => 'Sinuswelle', 'Color' => 0x0044FF, 'IconActive' => true, 'IconValue' => 'Drops'],
            ['Value' => 2, 'Caption' => 'Puls', 'Color' => 0xFF0000, 'IconActive' => true, 'IconValue' => 'Activity'],
            ['Value' => 3, 'Caption' => 'Atmen', 'Color' => 0x00FFFF, 'IconActive' => true, 'IconValue' => 'Wind'],
            ['Value' => 4, 'Caption' => 'Zufall', 'Color' => 0x888888, 'IconActive' => true, 'IconValue' => 'Shuffle'],
            ['Value' => 5, 'Caption' => 'Treppe', 'Color' => 0x00FF00, 'IconActive' => true, 'IconValue' => 'Graph'],
            ['Value' => 6, 'Caption' => 'Herzschlag', 'Color' => 0xFF00FF, 'IconActive' => true, 'IconValue' => 'Heart'],
            ['Value' => 7, 'Caption' => 'Zufalls-Mix', 'Color' => 0xFF9900, 'IconActive' => true, 'IconValue' => 'Shuffle'],
            ['Value' => 8, 'Caption' => 'Ein/Aus Intervall', 'Color' => 0xFFFFFF, 'IconActive' => true, 'IconValue' => 'Execute'],
        ]);
        
        $this->RegisterVariableInteger('Choreography', 'Muster', [
            'PRESENTATION' => $enumPres,
            'ICON' => 'Menu',
            'OPTIONS' => $choreoOptions
        ], 30);
        $this->EnableAction('Choreography');

        // Alte Variable entfernen, falls sie existiert
        $this->UnregisterVariable('ChoreographyActive');

        $this->RegisterVariableInteger('ChoreographySpeed', 'Geschwindigkeit', $percentPresentation, 50);
        $this->EnableAction('ChoreographySpeed');

        $this->RegisterVariableInteger('ChoreographyIntensity', 'Intensität', $percentPresentation, 60);
        $this->EnableAction('ChoreographyIntensity');

        if ($powerMeterID > 0) {
            $this->RegisterVariableFloat('CurrentPower', 'Aktuelle Leistung', [
                'PRESENTATION' => $valPres,
                'ICON' => 'Electricity',
                'SUFFIX' => ' W'
            ], 70);
        } else {
            $this->UnregisterVariable('CurrentPower');
        }

        // Migration: Delete legacy profile
        if (IPS_VariableProfileExists('SFTN.Choreography')) {
            IPS_DeleteVariableProfile('SFTN.Choreography');
        }

        // Set Default Values if unset
        if ($this->GetValue('ChoreographySpeed') == 0) {
            $this->SetValue('ChoreographySpeed', 100);
        }
        if ($this->GetValue('ChoreographyIntensity') == 0) {
            $this->SetValue('ChoreographyIntensity', 80);
        }

        // Ensure timer matches state
        $this->UpdateTimerState();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Active':
                if ($Value) {
                    $this->Activate();
                } else {
                    $this->Deactivate();
                }
                break;
            case 'PumpSpeed':
                $this->SetSpeed((int)$Value);
                break;
            case 'Choreography':
                if ($Value == 0) {
                    $this->StopChoreography();
                } else {
                    $this->StartChoreography($Value);
                }
                break;
            case 'ShowMode':
                $this->SetValue('ShowMode', (int)$Value);
                $this->ApplyShowMode((int)$Value);
                break;
            case 'ChoreographySpeed':
            case 'ChoreographyIntensity':
                $this->SetValue($Ident, $Value);
                break;
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('PowerMeterID')) {
            $this->SetValue('CurrentPower', $Data[0]);
            // Todo: Power Monitoring Logic for Dry-Run/Blockage
        }
    }

    public function SetSpeed(int $percent): void
    {
        $min = $this->ReadPropertyInteger('MinPumpPercent');
        $max = $this->ReadPropertyInteger('MaxPumpPercent');

        // Clamp
        if ($percent > 0 && $percent < $min) {
            $percent = $min;
        }
        if ($percent > $max) {
            $percent = $max;
        }

        $this->SetValue('PumpSpeed', $percent);
        $this->WriteTargetValue($percent);
    }

    public function Activate(): void
    {
        $this->SetValue('Active', true);
        $this->SLogInfo('Fountain Activated');
        // Preload directly
        $this->SetSpeed($this->ReadPropertyInteger('MinPumpPercent'));
    }

    public function Deactivate(): void
    {
        $this->SetValue('Active', false);
        $this->SetValue('Choreography', 0);
        $this->UpdateTimerState();
        $this->SLogInfo('Fountain Deactivated');
        // Stop directly
        $this->SetSpeed(0);
        $this->UpdateWLEDState(0);
        $this->SetValue('ShowMode', 0);
        $this->UpdateTwinklyState('off', 0);
        $sonosID = $this->ReadPropertyInteger('SonosDeviceID');
        if ($sonosID > 1 && @IPS_InstanceExists($sonosID)) {
            @SNS_Stop($sonosID);
        }
    }

    public function StartChoreography(int $mode): void
    {
        if ($mode === 0) {
            $this->StopChoreography();
            return;
        }

        if (!$this->GetValue('Active')) {
            $this->Activate();
        }
        $this->SetValue('Choreography', $mode);
        // Save start time for pattern generation
        $this->SetBuffer('ChoreographyStartTime', (string)microtime(true));
        
        // Reset Mix buffers for mode 7
        $this->SetBuffer('MixMode', '0');
        
        $this->UpdateTimerState();
        $this->SLogInfo("Choreography $mode started");
        $this->UpdateWLEDState($mode);
    }

    public function StopChoreography(): void
    {
        $this->SetValue('Choreography', 0);
        $this->UpdateTimerState();
        $this->SLogInfo('Choreography stopped');
        $this->UpdateWLEDState(0);
    }

    public function Tick(): void
    {
        if (!$this->GetValue('Active') || $this->GetValue('Choreography') == 0) {
            $this->UpdateTimerState();
            return;
        }

        $mode = $this->GetValue('Choreography');
        if ($mode === 0) {
            return; // Manuell
        }

        $startTime = (float)$this->GetBuffer('ChoreographyStartTime');
        if ($startTime == 0) {
            $startTime = microtime(true);
            $this->SetBuffer('ChoreographyStartTime', (string)$startTime);
        }

        $speed = $this->GetValue('ChoreographySpeed') / 100.0;
        $t = (microtime(true) - $startTime) * $speed;

        $rawValue = $this->CalculatePattern($mode, $t);

        $min = $this->ReadPropertyInteger('MinPumpPercent');
        $intensity = $this->GetValue('ChoreographyIntensity');

        $targetSpeed = (int)round($min + ($rawValue * ($intensity - $min)));
        
        // Ramp-Limiter
        $currentSpeed = $this->GetValue('PumpSpeed');
        $intervalMs = $this->ReadPropertyInteger('ChoreographyIntervalMs');
        
        $delta = $targetSpeed - $currentSpeed;
        if ($delta > 0) {
            $softStartMs = $this->ReadPropertyInteger('SoftStartMs');
            if ($softStartMs > 0) {
                $maxDelta = (int)ceil((100.0 / $softStartMs) * $intervalMs);
                if ($delta > $maxDelta) {
                    $targetSpeed = $currentSpeed + $maxDelta;
                }
            }
        } elseif ($delta < 0) {
            $softStopMs = $this->ReadPropertyInteger('SoftStopMs');
            if ($softStopMs > 0) {
                $maxDelta = (int)ceil((100.0 / $softStopMs) * $intervalMs);
                if (-$delta > $maxDelta) {
                    $targetSpeed = $currentSpeed - $maxDelta;
                }
            }
        }

        // Clamp to Max
        $max = $this->ReadPropertyInteger('MaxPumpPercent');
        if ($targetSpeed > $max) {
            $targetSpeed = $max;
        }

        // Deadzone filter - only update if change is > 0
        if (abs($targetSpeed - $currentSpeed) > 0) {
            $this->SetSpeed($targetSpeed);
        }
    }

    private function CalculatePattern(int $mode, float $t): float
    {
        switch ($mode) {
            case 1: // Sinuswelle (4s period)
                $period = 4.0;
                return (sin(2 * M_PI * $t / $period) + 1.0) / 2.0;

            case 2: // Puls / Shooter (3s cycle)
                $cycle = fmod($t, 3.0);
                if ($cycle < 0.2) {
                    return 0.0;
                } elseif ($cycle < 0.8) {
                    return 1.0;
                } elseif ($cycle < 1.0) {
                    return 1.0 - (($cycle - 0.8) / 0.2);
                } else {
                    return 0.0;
                }

            case 3: // Atmen (8s cycle)
                $cycle = fmod($t, 8.0);
                if ($cycle < 3.0) {
                    $val = sin(M_PI * $cycle / 6.0);
                    return $val * $val;
                } elseif ($cycle < 4.0) {
                    return 1.0;
                } elseif ($cycle < 6.0) {
                    $val = cos(M_PI * ($cycle - 4.0) / 4.0);
                    return $val * $val;
                } else {
                    return 0.0;
                }

            case 4: // Zufall
                // Interpolated Random Walk
                $lastRandom = (float)$this->GetBuffer('RandLast');
                $nextRandom = (float)$this->GetBuffer('RandNext');
                $lastTime = (float)$this->GetBuffer('RandTime');
                
                if ($lastTime == 0 || ($t - $lastTime) > 2.0) { // Every 2 seconds
                    $lastRandom = $nextRandom == 0 ? 0.5 : $nextRandom;
                    $nextRandom = mt_rand(0, 100) / 100.0;
                    $lastTime = $t;
                    $this->SetBuffer('RandLast', (string)$lastRandom);
                    $this->SetBuffer('RandNext', (string)$nextRandom);
                    $this->SetBuffer('RandTime', (string)$lastTime);
                }
                
                $progress = ($t - $lastTime) / 2.0;
                if ($progress > 1.0) $progress = 1.0;
                
                // smoothstep: 3x^2 - 2x^3
                $smooth = (3 * $progress * $progress) - (2 * $progress * $progress * $progress);
                
                return $lastRandom + (($nextRandom - $lastRandom) * $smooth);

            case 5: // Treppe (15s cycle, 5 steps)
                $cycle = fmod($t, 15.0);
                $steps = 5;
                if ($cycle < 7.5) {
                    $step = floor($cycle / 1.5);
                    return $step / ($steps - 1);
                } else {
                    $step = floor(($cycle - 7.5) / 1.5);
                    return 1.0 - ($step / ($steps - 1));
                }

            case 6: // Herzschlag (2s cycle)
                $cycle = fmod($t, 2.0);
                $t1 = 0.15;
                $t2 = 0.45;
                $var = 2 * (0.04 * 0.04);
                $p1 = exp(-pow($cycle - $t1, 2) / $var);
                $p2 = exp(-pow($cycle - $t2, 2) / $var) * 0.7;
                return max($p1, $p2);

            case 7: // Zufalls-Mix
                $currentMixMode = (int)$this->GetBuffer('MixMode');
                $mixStartTime = (float)$this->GetBuffer('MixStartTime');
                
                // Change pattern every 20 seconds
                if ($currentMixMode == 0 || ($t - $mixStartTime) > 20.0) {
                    $currentMixMode = mt_rand(1, 6);
                    $mixStartTime = $t;
                    $this->SetBuffer('MixMode', (string)$currentMixMode);
                    $this->SetBuffer('MixStartTime', (string)$mixStartTime);
                }
                
                $childT = $t - $mixStartTime; 
                return $this->CalculatePattern($currentMixMode, $childT);

            case 8: // Ein/Aus Intervall (4s cycle)
                $cycle = fmod($t, 4.0);
                return ($cycle < 2.0) ? 1.0 : 0.0;

            default:
                return 0.0;
        }
    }

    private function WriteTargetValue(int $percent): void
    {
        $targetID = $this->ReadPropertyInteger('PumpTargetID');
        if ($targetID > 0 && IPS_VariableExists($targetID)) {
            RequestAction($targetID, $percent);
        }
    }

    private function WLED_Set(int $instanceID, string $ident, mixed $value): void
    {
        if ($instanceID > 1 && @IPS_InstanceExists($instanceID)) {
            $varID = @IPS_GetObjectIDByIdent($ident, $instanceID);
            if ($varID > 1 && @IPS_VariableExists($varID)) {
                @RequestAction($varID, $value);
            }
        }
    }

    private function UpdateWLEDState(int $choreography): void
    {
        $wledID = $this->ReadPropertyInteger('WledDeviceID');
        $gardenID = $this->ReadPropertyInteger('WledGardenID');
        
        $hasWled = ($wledID > 1 && @IPS_InstanceExists($wledID));
        $hasGarden = ($gardenID > 1 && @IPS_InstanceExists($gardenID));

        if (!$hasWled && !$hasGarden) {
            return;
        }

        if (!$this->GetValue('Active')) {
            $this->WLED_Set($wledID, 'State', false);
            $this->WLED_Set($gardenID, 'State', false);
            return;
        }

        $this->WLED_Set($wledID, 'State', true);
        $this->WLED_Set($gardenID, 'State', true);

        // Map Symcon intensity (0-100) to WLED brightness/intensity (0-255)
        $intensity = $this->GetValue('ChoreographyIntensity');
        $wledBrightness = (int)round(($intensity / 100) * 255);
        $gardenBrightness = (int)round(($wledBrightness * 0.7)); // Garten etwas dunkler (70%)

        $this->WLED_Set($wledID, 'Brightness', $wledBrightness);
        $this->WLED_Set($gardenID, 'Brightness', $gardenBrightness);

        // Speed (0-100) to WLED speed (0-255)
        $speed = $this->GetValue('ChoreographySpeed');
        $wledSpeed = (int)round(($speed / 100) * 255);
        $gardenSpeed = (int)round(($wledSpeed * 0.3)); // Garten viel langsamer (30%)

        switch ($choreography) {
            case 0: // Manuell - Statisch Warmweiß / Gold
                $this->WLED_Set($wledID, 'Effect', 0); $this->WLED_Set($wledID, 'Color', 0xFF9900);
                $this->WLED_Set($gardenID, 'Effect', 0); $this->WLED_Set($gardenID, 'Color', 0xFF7700);
                break;
            case 1: // Sinuswelle - Breathe, Blue
                $this->WLED_Set($wledID, 'Effect', 2); $this->WLED_Set($wledID, 'Color', 0x0044FF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 2); $this->WLED_Set($gardenID, 'Color', 0x001155); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
            case 2: // Puls - Heartbeat, Red
                $this->WLED_Set($wledID, 'Effect', 65); $this->WLED_Set($wledID, 'Color', 0xFF0000); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 0); $this->WLED_Set($gardenID, 'Color', 0x440000);
                break;
            case 3: // Atmen - Fade, Cyan
                $this->WLED_Set($wledID, 'Effect', 12); $this->WLED_Set($wledID, 'Color', 0x00FFFF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 12); $this->WLED_Set($gardenID, 'Color', 0x004488); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
            case 4: // Zufall - Chase Random
                $this->WLED_Set($wledID, 'Effect', 74); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 9); $this->WLED_Set($gardenID, 'Speed', 20);
                break;
            case 5: // Treppe - Rainbow
                $this->WLED_Set($wledID, 'Effect', 11); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 11); $this->WLED_Set($gardenID, 'Speed', 15);
                break;
            case 6: // Herzschlag - Heartbeat, Magenta
                $this->WLED_Set($wledID, 'Effect', 65); $this->WLED_Set($wledID, 'Color', 0xFF00FF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 2); $this->WLED_Set($gardenID, 'Color', 0x440044); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
            case 7: // Zufalls-Mix
                $this->WLED_Set($wledID, 'Effect', 9); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 9); $this->WLED_Set($gardenID, 'Speed', 20);
                break;
            case 8: // Ein/Aus Intervall - Blink, White
                $this->WLED_Set($wledID, 'Effect', 1); $this->WLED_Set($wledID, 'Color', 0xFFFFFF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 1); $this->WLED_Set($gardenID, 'Color', 0xFFFFFF); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
        }
    }

    public function ApplyShowMode(int $mode): void
    {
        // Scene definitions: [choreography, speed, intensity, theme, twinklyMode, twinklyBrightness]
        $scenes = [
            // 0 = Manuell -> Do nothing, user controls manually
            1 => ['choreo' => 3, 'speed' => 30,  'intensity' => 40,  'theme' => 'zen',       'twMode' => 'movie', 'twBright' => 30],  // Dinner
            2 => ['choreo' => 7, 'speed' => 80,  'intensity' => 90,  'theme' => null,        'twMode' => 'movie', 'twBright' => 100], // Party (no synth, WLED sound reactive)
            3 => ['choreo' => 1, 'speed' => 50,  'intensity' => 60,  'theme' => 'zen',       'twMode' => 'movie', 'twBright' => 20],  // Zen
            4 => ['choreo' => 6, 'speed' => 40,  'intensity' => 50,  'theme' => 'mystisch',  'twMode' => 'movie', 'twBright' => 15],  // Romantik
            5 => ['choreo' => 5, 'speed' => 60,  'intensity' => 70,  'theme' => 'karibik',   'twMode' => 'movie', 'twBright' => 70],  // Regenbogen
            6 => ['choreo' => 1, 'speed' => 70,  'intensity' => 80,  'theme' => null,        'twMode' => 'movie', 'twBright' => 80],  // Musik-Show (no synth, WLED sound reactive)
        ];

        if ($mode === 0) {
            $this->SLogInfo('ShowMode: Manuell');
            return;
        }

        if (!isset($scenes[$mode])) {
            return;
        }

        $scene = $scenes[$mode];
        $this->SLogInfo("ShowMode: Applying scene $mode");

        // 1. Set choreography parameters
        $this->SetValue('ChoreographySpeed', $scene['speed']);
        $this->SetValue('ChoreographyIntensity', $scene['intensity']);

        // 2. Start choreography (this also activates pump + WLED)
        $this->StartChoreography($scene['choreo']);

        // 3. For Party/Musik-Show: Switch WLED to Sound Reactive mode
        if ($mode === 2 || $mode === 6) {
            $this->SetWLEDSoundReactive();
        }

        // 4. Twinkly
        $this->UpdateTwinklyState($scene['twMode'], $scene['twBright']);

        // 5. Synth + Sonos (only for scenes with a theme)
        if ($scene['theme'] !== null) {
            $this->PreRenderAndPlaySound($scene['choreo'], $scene['theme']);
        }
    }

    private function Twinkly_Set(int $instanceID, string $ident, mixed $value): void
    {
        if ($instanceID > 1 && @IPS_InstanceExists($instanceID)) {
            $varID = @IPS_GetObjectIDByIdent($ident, $instanceID);
            if ($varID > 1 && @IPS_VariableExists($varID)) {
                @RequestAction($varID, $value);
            }
        }
    }

    private function UpdateTwinklyState(string $mode, int $brightness): void
    {
        $twinklyID = $this->ReadPropertyInteger('TwinklyDeviceID');
        if ($twinklyID <= 1 || !@IPS_InstanceExists($twinklyID)) {
            return;
        }

        if ($mode === 'off') {
            $this->Twinkly_Set($twinklyID, 'Switch', false);
            return;
        }

        $this->Twinkly_Set($twinklyID, 'Switch', true);
        
        $modeInt = 2; // Default to Movie
        if ($mode === 'color') $modeInt = 0;
        elseif ($mode === 'effect') $modeInt = 1;
        elseif ($mode === 'movie') $modeInt = 2;
        elseif ($mode === 'demo') $modeInt = 3;
        
        $this->Twinkly_Set($twinklyID, 'Mode', $modeInt);
        $this->Twinkly_Set($twinklyID, 'Brightness', $brightness);
    }

    private function SetWLEDSoundReactive(): void
    {
        $wledID = $this->ReadPropertyInteger('WledDeviceID');
        $gardenID = $this->ReadPropertyInteger('WledGardenID');

        // Effect 74 = 'GEQ' (Graphic Equalizer) - a popular sound reactive effect
        if ($wledID > 1 && @IPS_InstanceExists($wledID)) {
            @WLED_SetEffect($wledID, 74);
        }
        if ($gardenID > 1 && @IPS_InstanceExists($gardenID)) {
            @WLED_SetEffect($gardenID, 74);
        }
    }

    private function PreRenderAndPlaySound(int $choreo, string $theme): void
    {
        $synthUrl = $this->ReadPropertyString('SynthBaseUrl');
        $sonosID = $this->ReadPropertyInteger('SonosDeviceID');
        $duration = $this->ReadPropertyInteger('ShowDurationSec');

        if (empty($synthUrl) || $sonosID <= 1 || !@IPS_InstanceExists($sonosID)) {
            return;
        }

        // 1. Pre-calculate h(t) for the entire show duration
        $intervalSec = $this->ReadPropertyInteger('ChoreographyIntervalMs') / 1000.0;
        $speed = $this->GetValue('ChoreographySpeed') / 100.0;
        $numSamples = (int)ceil($duration / $intervalSec);
        $heights = [];

        for ($i = 0; $i < $numSamples; $i++) {
            $t = $i * $intervalSec * $speed;
            $heights[] = round($this->CalculatePatternStatic($choreo, $t), 4);
        }

        // 2. Send to Docker synth
        $payload = json_encode([
            'heights' => $heights,
            'theme' => $theme,
            'duration' => $duration,
        ]);

        $ch = curl_init($synthUrl . '/render');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            $this->SLogError('FountainSynth: Render failed (HTTP ' . $httpCode . ')');
            return;
        }

        $result = json_decode($response, true);
        if (!isset($result['url'])) {
            $this->SLogError('FountainSynth: Invalid response');
            return;
        }

        // 3. Start Sonos playback
        $audioUrl = $synthUrl . $result['url'];
        @SNS_SetAVTransportURI($sonosID, $audioUrl);
        @SNS_Play($sonosID);
        $this->SLogInfo('FountainSynth: Playing ' . $theme . ' (' . $result['render_time_ms'] . 'ms render)');
    }

    private function CalculatePatternStatic(int $mode, float $t): float
    {
        switch ($mode) {
            case 1: // Sinuswelle
                return (sin(2 * M_PI * $t / 4.0) + 1.0) / 2.0;
            case 2: // Puls
                $cycle = fmod($t, 3.0);
                if ($cycle < 0.2) return 0.0;
                if ($cycle < 0.8) return 1.0;
                if ($cycle < 1.0) return 1.0 - (($cycle - 0.8) / 0.2);
                return 0.0;
            case 3: // Atmen
                $cycle = fmod($t, 8.0);
                if ($cycle < 3.0) { $v = sin(M_PI * $cycle / 6.0); return $v * $v; }
                if ($cycle < 4.0) return 1.0;
                if ($cycle < 6.0) { $v = cos(M_PI * ($cycle - 4.0) / 4.0); return $v * $v; }
                return 0.0;
            case 5: // Treppe
                $cycle = fmod($t, 15.0);
                if ($cycle < 7.5) return floor($cycle / 1.5) / 4;
                return 1.0 - (floor(($cycle - 7.5) / 1.5) / 4);
            case 6: // Herzschlag
                $cycle = fmod($t, 2.0);
                $p1 = exp(-pow($cycle - 0.15, 2) / (2 * 0.04 * 0.04));
                $p2 = exp(-pow($cycle - 0.45, 2) / (2 * 0.04 * 0.04)) * 0.7;
                return max($p1, $p2);
            case 8: // Ein/Aus Intervall
                $cycle = fmod($t, 4.0);
                return ($cycle < 2.0) ? 1.0 : 0.0;
            default: // Fallback for random/mix: use sine
                return (sin(2 * M_PI * $t / 4.0) + 1.0) / 2.0;
        }
    }

    private function UpdateTimerState(): void
    {
        if ($this->GetValue('Active') && $this->GetValue('Choreography') > 0) {
            $interval = $this->ReadPropertyInteger('ChoreographyIntervalMs');
            $this->SetTimerInterval('ChoreographyTimer', $interval);
        } else {
            $this->SetTimerInterval('ChoreographyTimer', 0);
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "SmartFountain steuert eine Pumpe (0-100%) mit vordefinierten Mustern."
        },
        {
            "type": "SelectVariable",
            "name": "PumpTargetID",
            "caption": "Pumpen-Variable (z.B. Shelly Brightness)"
        },
        {
            "type": "SelectVariable",
            "name": "PowerMeterID",
            "caption": "Leistungsmessung (optional, Shelly Power)"
        },
        {
            "type": "SelectInstance",
            "name": "WledDeviceID",
            "caption": "WLED: Springbrunnen LED-Ring"
        },
        {
            "type": "SelectInstance",
            "name": "WledGardenID",
            "caption": "WLED: Garten DMX-Spots (optional)"
        },
        {
            "type": "SelectInstance",
            "name": "TwinklyDeviceID",
            "caption": "Twinkly Lichterkette (optional)"
        },
        {
            "type": "SelectInstance",
            "name": "SonosDeviceID",
            "caption": "Sonos Player (optional)"
        },
        {
            "type": "ValidationTextBox",
            "name": "SynthBaseUrl",
            "caption": "FountainSynth Docker URL"
        },
        {
            "type": "NumberSpinner",
            "name": "ShowDurationSec",
            "caption": "Show-Dauer (Sekunden)",
            "minimum": 5,
            "maximum": 300
        },
        {
            "type": "Label",
            "caption": "Sicherheit & Limits"
        },
        {
            "type": "NumberSpinner",
            "name": "MinPumpPercent",
            "caption": "Minimale Leistung (Pre-Load in %)",
            "minimum": 0,
            "maximum": 50
        },
        {
            "type": "NumberSpinner",
            "name": "MaxPumpPercent",
            "caption": "Maximale Leistung (%)",
            "minimum": 10,
            "maximum": 100
        },
        {
            "type": "NumberSpinner",
            "name": "SoftStartMs",
            "caption": "Soft-Start Rampenzeit (ms)",
            "minimum": 0,
            "maximum": 5000
        },
        {
            "type": "NumberSpinner",
            "name": "SoftStopMs",
            "caption": "Soft-Stop Rampenzeit (ms)",
            "minimum": 0,
            "maximum": 5000
        },
        {
            "type": "Label",
            "caption": "Choreografie-Engine"
        },
        {
            "type": "NumberSpinner",
            "name": "ChoreographyIntervalMs",
            "caption": "Tick-Intervall (ms)",
            "minimum": 20,
            "maximum": 1000
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Pumpe Aktivieren",
            "onClick": "SFTN_Activate($id);"
        },
        {
            "type": "Button",
            "label": "Pumpe Deaktivieren",
            "onClick": "SFTN_Deactivate($id);"
        }
    ],
    "status": [
        {
            "code": 102,
            "icon": "active",
            "caption": "Aktiv"
        }
    ]
}
EOT;
    }
}
