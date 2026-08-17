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
        $this->RegisterPropertyInteger('ShellyStateID', 0);
        $this->RegisterPropertyInteger('WledDeviceID', 0);
        $this->RegisterPropertyInteger('WledGardenID', 0);
        $this->RegisterPropertyInteger('TwinklyDeviceID', 0);
        $this->RegisterPropertyInteger('SonosDeviceID', 0);
        $this->RegisterPropertyString('SynthBaseUrl', 'http://10.1.60.150:5000');
        $this->RegisterPropertyInteger('ShowDurationSec', 20);

        $this->RegisterPropertyInteger('WledStateID', 0);
        $this->RegisterPropertyInteger('WledBrightnessID', 0);
        $this->RegisterPropertyInteger('WledEffectID', 0);
        $this->RegisterPropertyInteger('WledSpeedID', 0);
        $this->RegisterPropertyInteger('WledColorID', 0);

        
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

        $this->RegisterVariableInteger('ShowMode', 'Szenen-Modus', [
            'PRESENTATION' => $enumPres,
            'ICON' => 'Star',
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
            ['Value' => 0, 'Caption' => 'Aus (Manuell)', 'Color' => 0x888888, 'IconActive' => true, 'IconValue' => 'Close'],
            ['Value' => 1, 'Caption' => 'Sinuswelle', 'Color' => 0x00FF00, 'IconActive' => true, 'IconValue' => 'Wave'],
            ['Value' => 2, 'Caption' => 'Sägezahn (Crescendo)', 'Color' => 0xFF00FF, 'IconActive' => true, 'IconValue' => 'Graph'],
            ['Value' => 3, 'Caption' => 'High / Low (Plateau)', 'Color' => 0xFFFF00, 'IconActive' => true, 'IconValue' => 'Move'],
            ['Value' => 4, 'Caption' => 'Träger Zufall', 'Color' => 0x00FFFF, 'IconActive' => true, 'IconValue' => 'Shuffle'],
            ['Value' => 8, 'Caption' => 'Ein/Aus Intervall', 'Color' => 0xFFFFFF, 'IconActive' => true, 'IconValue' => 'Execute'],
            ['Value' => 9, 'Caption' => 'Geysir (Schuss)', 'Color' => 0x00FFFF, 'IconActive' => true, 'IconValue' => 'Drop'],
        ]);

        $oldChoreo = @$this->GetValue('Choreography');
        $this->UnregisterVariable('Choreography');

        $this->RegisterVariableInteger('Choreography', 'Muster', [
            'PRESENTATION' => $enumPres,
            'ICON' => 'Menu',
            'OPTIONS' => $choreoOptions
        ], 30);
        $this->EnableAction('Choreography');
        
        if ($oldChoreo !== false) {
            $this->SetValue('Choreography', $oldChoreo);
        }

        $this->RegisterVariableBoolean('EnableLight', 'Licht (WLED/Twinkly)', [
            'PRESENTATION' => $switchPres,
            'ICON' => 'Light'
        ], 40);
        $this->EnableAction('EnableLight');

        $this->RegisterVariableBoolean('EnableAudio', 'Audio (Sonos)', [
            'PRESENTATION' => $switchPres,
            'ICON' => 'Speaker'
        ], 45);
        $this->EnableAction('EnableAudio');

        $this->RegisterVariableBoolean('EnableDamping', 'Dämpfung (Soft-Start/Stop)', [
            'PRESENTATION' => $switchPres,
            'ICON' => 'Speedo'
        ], 48);
        $this->EnableAction('EnableDamping');

        // Alte Variable entfernen, falls sie existiert
        $this->UnregisterVariable('ChoreographyActive');

        $this->RegisterVariableInteger('ChoreographySpeed', 'Geschwindigkeit', $percentPresentation, 50);
        $this->EnableAction('ChoreographySpeed');

        $this->RegisterVariableInteger('ChoreographyIntensity', 'Intensität', $percentPresentation, 60);
        $this->UnregisterVariable('CurrentPower');

        if ($this->GetValue('ChoreographySpeed') == 0) {
            $this->SetValue('ChoreographySpeed', 100);
        }
        if ($this->GetValue('ChoreographyIntensity') == 0) {
            $this->SetValue('ChoreographyIntensity', 80);
        }
        
        // Defaults for toggles if they were just created
        if (!IPS_HasChanges($this->InstanceID)) {
            // Dies läuft nach dem ersten Erstellen, wir lassen sie standardmäßig an.
            // (symcon idents exist, but we can't reliably check creation time here easily)
        }

        // Ensure timer matches state
        $this->UpdateTimerState();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Active':
                $this->SetValue($Ident, $Value);
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
                $this->SetValue($Ident, $Value);
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
                if ($this->GetValue('Active')) {
                    $this->UpdateWLEDState($this->GetValue('Choreography'));
                }
                break;
            case 'EnableLight':
                $this->SetValue($Ident, $Value);
                if ($this->GetValue('Active')) {
                    if (!$Value) {
                        $this->UpdateWLEDState(0);
                        $this->UpdateTwinklyState('off', 0);
                    } else {
                        // Re-apply to restore lights
                        $mode = $this->GetValue('ShowMode');
                        if ($mode > 0) {
                            $this->ApplyShowMode($mode);
                        } else {
                            $this->UpdateWLEDState($this->GetValue('Choreography'));
                        }
                    }
                }
                break;
            case 'EnableAudio':
                $this->SetValue($Ident, $Value);
                if (!$Value) {
                    // Stop audio immediately
                    $sonosID = $this->ReadPropertyInteger('SonosDeviceID');
                    if ($sonosID > 1 && @IPS_InstanceExists($sonosID)) {
                        try {
                            @SNS_Stop($sonosID);
                        } catch (Exception $e) {
                            $this->SLogError('Sonos Stop Error: ' . $e->getMessage());
                        }
                    }
                }
                break;
            case 'EnableDamping':
                $this->SetValue($Ident, $Value);
                break;
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
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
        
        $shellyID = $this->ReadPropertyInteger('ShellyStateID');
        if ($shellyID > 1 && @IPS_ObjectExists($shellyID)) {
            @RequestAction($shellyID, true);
        }

        // Preload directly
        $this->SetSpeed($this->ReadPropertyInteger('MinPumpPercent'));
    }

    public function Deactivate(): void
    {
        $this->SetValue('Active', false);
        $this->SetValue('Choreography', 0);
        $this->UpdateTimerState();
        $this->SLogInfo('Fountain Deactivated');
        
        $shellyID = $this->ReadPropertyInteger('ShellyStateID');
        if ($shellyID > 1 && @IPS_ObjectExists($shellyID)) {
            @RequestAction($shellyID, false);
        }
        
        // Stop directly
        $this->SetSpeed(0);
        $this->UpdateWLEDState(0);
        $this->SetValue('ShowMode', 0);
        $this->UpdateTwinklyState('off', 0);
        
        if ($this->GetValue('EnableAudio')) {
            $sonosID = $this->ReadPropertyInteger('SonosDeviceID');
            if ($sonosID > 1 && @IPS_InstanceExists($sonosID)) {
                try {
                    @SNS_Stop($sonosID);
                } catch (Exception $e) {
                    $this->SLogError('Sonos Stop Error: ' . $e->getMessage());
                }
            }
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
        
        // Restore relay if it was switched off by Mode 9
        if ($this->GetValue('Active')) {
            $shellyID = $this->ReadPropertyInteger('ShellyStateID');
            if ($shellyID > 1 && @IPS_ObjectExists($shellyID) && !GetValue($shellyID)) {
                @RequestAction($shellyID, true);
            }
        }
        
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

        // Immer hart von 0% bis zur gewünschten Intensität springen (für alle Effekte)
        $targetSpeed = (int)round($rawValue * $intensity);
        
        // Ramp-Limiter
        $currentSpeed = $this->GetValue('PumpSpeed');
        $intervalMs = $this->ReadPropertyInteger('ChoreographyIntervalMs');
        
        $delta = $targetSpeed - $currentSpeed;
        if ($this->GetValue('EnableDamping') && $mode !== 9) {
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

            case 2: // Sägezahn (Crescendo) - 10s cycle
                $cycle = fmod($t, 10.0);
                if ($cycle < 8.0) {
                    return $cycle / 8.0;
                } else {
                    return 0.0;
                }

            case 3: // High / Low (Plateau) - 8s cycle
                $cycle = fmod($t, 8.0);
                return ($cycle < 4.0) ? 0.3 : 1.0;

            case 4: // Träger Zufall
                $lastTime = (float)$this->GetBuffer('RandTime');
                $lastRandom = (float)$this->GetBuffer('RandLast');
                
                // Change every 6 seconds (at 100% speed)
                if ($lastTime == 0 || ($t - $lastTime) > 6.0) {
                    $lastRandom = mt_rand(20, 100) / 100.0; // Between 0.2 and 1.0
                    $lastTime = $t;
                    $this->SetBuffer('RandLast', (string)$lastRandom);
                    $this->SetBuffer('RandTime', (string)$lastTime);
                }
                return $lastRandom;

            case 8: // Ein/Aus Intervall
                $intensity = $this->GetValue('ChoreographyIntensity') / 100.0;
                return (fmod($t, 2.0) < (2.0 * $intensity)) ? 1.0 : 0.0;

            case 9: // Geysir (Schuss)
                $speedPercent = $this->GetValue('ChoreographySpeed');
                if ($speedPercent <= 0) $speedPercent = 100;
                $realTime = $t / ($speedPercent / 100.0);
                
                $onDuration = max(0.1, ($speedPercent / 100.0) * 2.0);
                $cycleTime = $onDuration + 5.0; // 5 seconds pause
                
                return (fmod($realTime, $cycleTime) < $onDuration) ? 1.0 : 0.0;

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
        if ($instanceID <= 1 || !@IPS_InstanceExists($instanceID)) {
            return;
        }

        // 1. Direkte Variablen-Overrides prüfen (nur für den Haupt-LED-Ring)
        if ($instanceID === $this->ReadPropertyInteger('WledDeviceID')) {
            $overrideID = 0;
            switch ($ident) {
                case 'State': $overrideID = $this->ReadPropertyInteger('WledStateID'); break;
                case 'Brightness': $overrideID = $this->ReadPropertyInteger('WledBrightnessID'); break;
                case 'Effect': $overrideID = $this->ReadPropertyInteger('WledEffectID'); break;
                case 'Speed': $overrideID = $this->ReadPropertyInteger('WledSpeedID'); break;
                case 'Color': $overrideID = $this->ReadPropertyInteger('WledColorID'); break;
            }
            if ($overrideID > 1 && @IPS_VariableExists($overrideID)) {
                try {
                    RequestAction($overrideID, $value);
                    return; // Erfolgreich gesetzt via Override
                } catch (Exception $e) {
                    $this->SLogError("WLED_Set: Fehler bei Override $ident: " . $e->getMessage());
                    return;
                }
            }
        }

        // 2. Fallback: Automatische Suche über Identifikatoren
        $identsToTry = [$ident];
        if ($ident === 'Effect') { $identsToTry[] = 'EffectId'; $identsToTry[] = 'Effects'; }
        if ($ident === 'Speed') { $identsToTry[] = 'EffectSpeed'; }
        if ($ident === 'Color') { $identsToTry[] = 'Color1'; $identsToTry[] = 'PrimaryColor'; }
        if ($ident === 'State') { $identsToTry[] = 'Status'; }

        foreach ($identsToTry as $testIdent) {
            $varID = @IPS_GetObjectIDByIdent($testIdent, $instanceID);
            if ($varID > 1 && @IPS_VariableExists($varID)) {
                try {
                    RequestAction($varID, $value);
                    return; // Erfolgreich gesetzt
                } catch (Exception $e) {
                    $this->SLogError("WLED_Set: Fehler bei $testIdent: " . $e->getMessage());
                }
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

        if (!$this->GetValue('Active') || !$this->GetValue('EnableLight')) {
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
            case 2: // Sägezahn - Fade, Purple
                $this->WLED_Set($wledID, 'Effect', 12); $this->WLED_Set($wledID, 'Color', 0xFF00FF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 12); $this->WLED_Set($gardenID, 'Color', 0x440044); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
            case 3: // High/Low - Blink, Yellow
                $this->WLED_Set($wledID, 'Effect', 1); $this->WLED_Set($wledID, 'Color', 0xFFFF00); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 1); $this->WLED_Set($gardenID, 'Color', 0x888800); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
            case 4: // Träger Zufall - Random, Cyan
                $this->WLED_Set($wledID, 'Effect', 74); $this->WLED_Set($wledID, 'Color', 0x00FFFF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 9); $this->WLED_Set($gardenID, 'Color', 0x004488); $this->WLED_Set($gardenID, 'Speed', 20);
                break;
            case 8: // Ein/Aus Intervall - Blink, White
                $this->WLED_Set($wledID, 'Effect', 1); $this->WLED_Set($wledID, 'Color', 0xFFFFFF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 1); $this->WLED_Set($gardenID, 'Color', 0xFFFFFF); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
            case 9: // Geysir - Strobe/Blink, Cyan
                $this->WLED_Set($wledID, 'Effect', 1); $this->WLED_Set($wledID, 'Color', 0x00FFFF); $this->WLED_Set($wledID, 'Speed', $wledSpeed);
                $this->WLED_Set($gardenID, 'Effect', 1); $this->WLED_Set($gardenID, 'Color', 0x008888); $this->WLED_Set($gardenID, 'Speed', $gardenSpeed);
                break;
        }
    }

    public function ApplyShowMode(int $mode): void
    {
        // Scene definitions: [choreography, speed, intensity, theme, twinklyMode, twinklyBrightness]
        $scenes = [
            // 0 = Manuell -> Do nothing, user controls manually
            1 => ['choreo' => 3, 'speed' => 30,  'intensity' => 40,  'theme' => 'zen',       'twMode' => 'movie', 'twBright' => 30],  // Dinner (High / Low)
            2 => ['choreo' => 9, 'speed' => 80,  'intensity' => 90,  'theme' => null,        'twMode' => 'movie', 'twBright' => 100], // Party (Geysir)
            3 => ['choreo' => 2, 'speed' => 50,  'intensity' => 60,  'theme' => 'zen',       'twMode' => 'movie', 'twBright' => 20],  // Zen (Sägezahn)
            4 => ['choreo' => 4, 'speed' => 40,  'intensity' => 50,  'theme' => 'mystisch',  'twMode' => 'movie', 'twBright' => 15],  // Romantik (Träger Zufall)
            5 => ['choreo' => 8, 'speed' => 60,  'intensity' => 70,  'theme' => 'karibik',   'twMode' => 'movie', 'twBright' => 70],  // Regenbogen (Ein/Aus)
            6 => ['choreo' => 1, 'speed' => 70,  'intensity' => 80,  'theme' => null,        'twMode' => 'movie', 'twBright' => 80],  // Musik-Show (Sinus)
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

        if ($mode === 'off' || !$this->GetValue('EnableLight')) {
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
        $this->WLED_Set($wledID, 'Effect', 74);
        $this->WLED_Set($gardenID, 'Effect', 74);
    }

    private function PreRenderAndPlaySound(int $choreo, string $theme): void
    {
        if (!$this->GetValue('EnableAudio')) {
            return; // Audio disabled
        }

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
        try {
            @SNS_SetAVTransportURI($sonosID, $audioUrl);
            @SNS_Play($sonosID);
            $this->SLogInfo('FountainSynth: Playing ' . $theme . ' (' . $result['render_time_ms'] . 'ms render)');
        } catch (Exception $e) {
            $this->SLogError('Sonos Play Error: ' . $e->getMessage());
        }
    }

    private function CalculatePatternStatic(int $mode, float $t): float
    {
        switch ($mode) {
            case 1: // Sinuswelle
                return (sin(2 * M_PI * $t / 4.0) + 1.0) / 2.0;

            case 2: // Sägezahn (Crescendo)
                $cycle = fmod($t, 10.0);
                return ($cycle < 8.0) ? ($cycle / 8.0) : 0.0;

            case 3: // High / Low (Plateau)
                $cycle = fmod($t, 8.0);
                return ($cycle < 4.0) ? 0.3 : 1.0;

            case 4: // Träger Zufall (Static fake)
                $step = floor($t / 6.0);
                $fakeRandom = (sin($step * 12.9898) * 43758.5453) - floor(sin($step * 12.9898) * 43758.5453);
                return 0.2 + ($fakeRandom * 0.8);

            case 8: // Ein/Aus Intervall
                $intensity = $this->GetValue('ChoreographyIntensity') / 100.0;
                return (fmod($t, 2.0) < (2.0 * $intensity)) ? 1.0 : 0.0;
            case 9: // Geysir (Schuss)
                $speedPercent = $this->GetValue('ChoreographySpeed');
                if ($speedPercent <= 0) $speedPercent = 100;
                $realTime = $t / ($speedPercent / 100.0);
                
                $onDuration = max(0.1, ($speedPercent / 100.0) * 2.0);
                $cycleTime = $onDuration + 5.0; // 5 seconds pause
                
                return (fmod($realTime, $cycleTime) < $onDuration) ? 1.0 : 0.0;
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
            "name": "ShellyStateID",
            "caption": "Pumpe Ein/Aus Schalter (Shelly State)"
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
            "type": "ExpansionPanel",
            "caption": "WLED Experteneinstellungen (Direkte Variablen für LED-Ring)",
            "items": [
                {
                    "type": "Label",
                    "caption": "Wenn WLED-Instanzen nicht korrekt erkannt werden, können hier die exakten Variablen verlinkt werden."
                },
                { "type": "SelectVariable", "name": "WledStateID", "caption": "Ein/Aus (State)" },
                { "type": "SelectVariable", "name": "WledBrightnessID", "caption": "Helligkeit (Brightness)" },
                { "type": "SelectVariable", "name": "WledEffectID", "caption": "Effekte (Effect)" },
                { "type": "SelectVariable", "name": "WledSpeedID", "caption": "Effekt Geschwindigkeit (Speed)" },
                { "type": "SelectVariable", "name": "WledColorID", "caption": "Farbe 1 (Color)" }
            ]
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
