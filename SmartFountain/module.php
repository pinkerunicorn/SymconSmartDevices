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
        
        $this->RegisterPropertyInteger('MinPumpPercent', 5);
        $this->RegisterPropertyInteger('MaxPumpPercent', 100);
        $this->RegisterPropertyInteger('SoftStartMs', 200);
        $this->RegisterPropertyInteger('SoftStopMs', 500);
        
        $this->RegisterPropertyInteger('BasinDiameterCm', 100);
        $this->RegisterPropertyInteger('BasinDepthCm', 30);
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

        // --- Variables ---
        $this->MaintainVariable('Active', 'Aktiv', 0, '', 10, true);
        $this->EnableAction('Active');

        $this->MaintainVariable('PumpSpeed', 'Pumpengeschwindigkeit', 1, '', 20, true);
        $this->EnableAction('PumpSpeed');

        $this->MaintainVariable('Choreography', 'Muster', 1, 'SFTN.Choreography', 30, true);
        $this->EnableAction('Choreography');

        $this->MaintainVariable('ChoreographyActive', 'Choreografie aktiv', 0, '', 40, true);
        $this->EnableAction('ChoreographyActive');

        $this->MaintainVariable('ChoreographySpeed', 'Geschwindigkeit', 1, '', 50, true);
        $this->EnableAction('ChoreographySpeed');

        $this->MaintainVariable('ChoreographyIntensity', 'Intensität', 1, '', 60, true);
        $this->EnableAction('ChoreographyIntensity');

        $this->MaintainVariable('CurrentPower', 'Aktuelle Leistung', 2, '', 70, $powerMeterID > 0);

        $this->SetupVariablePresentations();

        if (!IPS_VariableProfileExists('SFTN.Choreography')) {
            IPS_CreateVariableProfile('SFTN.Choreography', 1);
            IPS_SetVariableProfileIcon('SFTN.Choreography', 'Menu');
        }
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 0, 'Manuell', '', 0x000000);
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 1, 'Sinuswelle', '', 0x000000);
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 2, 'Puls', '', 0x000000);
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 3, 'Atmen', '', 0x000000);
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 4, 'Zufall', '', 0x000000);
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 5, 'Treppe', '', 0x000000);
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 6, 'Herzschlag', '', 0x000000);
        IPS_SetVariableProfileAssociation('SFTN.Choreography', 7, 'Zufalls-Mix', '', 0x000000);

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

    private function SetupVariablePresentations(): void
    {
        $valPres = defined('VARIABLE_PRESENTATION_VALUE_PRESENTATION') ? VARIABLE_PRESENTATION_VALUE_PRESENTATION : 1;
        $switchPres = defined('VARIABLE_PRESENTATION_SWITCH') ? VARIABLE_PRESENTATION_SWITCH : 3;
        $sliderPres = defined('VARIABLE_PRESENTATION_SLIDER') ? VARIABLE_PRESENTATION_SLIDER : 2;
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Active'), [
            'PRESENTATION' => $switchPres,
            'ICON' => 'Power'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ChoreographyActive'), [
            'PRESENTATION' => $switchPres,
            'ICON' => 'Power'
        ]);

        $percentPresentation = [
            'PRESENTATION' => $sliderPres,
            'ICON' => 'Intensity',
            'MIN' => 0,
            'MAX' => 100,
            'STEP' => 1,
            'SUFFIX' => ' %'
        ];
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('PumpSpeed'), $percentPresentation);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ChoreographySpeed'), $percentPresentation);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ChoreographyIntensity'), $percentPresentation);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('CurrentPower'), [
            'PRESENTATION' => $valPres,
            'ICON' => 'Electricity',
            'SUFFIX' => ' W'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Choreography'), [
            'PRESENTATION' => $valPres,
            'ICON' => 'Menu',
            'ASSOCIATIONS' => [
                [0, 'Manuell', '', -1],
                [1, 'Sinuswelle', '', -1],
                [2, 'Puls', '', -1],
                [3, 'Atmen', '', -1],
                [4, 'Zufall', '', -1],
                [5, 'Treppe', '', -1],
                [6, 'Herzschlag', '', -1],
                [7, 'Zufalls-Mix', '', -1],
            ]
        ]);
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
                    $this->SetValue($Ident, 0);
                } else {
                    $this->StartChoreography($Value);
                }
                break;
            case 'ChoreographyActive':
                if ($Value) {
                    $this->StartChoreography($this->GetValue('Choreography'));
                } else {
                    $this->StopChoreography();
                }
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
        $this->SetValue('ChoreographyActive', false);
        $this->UpdateTimerState();
        $this->SLogInfo('Fountain Deactivated');
        // Stop directly
        $this->SetSpeed(0);
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
        $this->SetValue('ChoreographyActive', true);
        // Save start time for pattern generation
        $this->SetBuffer('ChoreographyStartTime', (string)microtime(true));
        
        // Reset Mix buffers for mode 7
        $this->SetBuffer('MixMode', '0');
        
        $this->UpdateTimerState();
        $this->SLogInfo("Choreography $mode started");
    }

    public function StopChoreography(): void
    {
        $this->SetValue('ChoreographyActive', false);
        $this->UpdateTimerState();
        $this->SLogInfo('Choreography stopped');
    }

    public function Tick(): void
    {
        if (!$this->GetValue('Active') || !$this->GetValue('ChoreographyActive')) {
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

    private function UpdateTimerState(): void
    {
        if ($this->GetValue('Active') && $this->GetValue('ChoreographyActive')) {
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
