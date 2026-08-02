<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
/**
 * ImperialDishwasherAI — KI-gestützte Spülmaschinen-Überwachung.
 * Nutzt SmartGeminiIO für alle Gemini-API-Aufrufe.
 */
class ImperialDishwasherAI extends IPSModuleStrict {
    use SmartLog_Trait;
    /** GUID des SmartGeminiIO-Moduls zur Auto-Discovery */
    private const GEMINI_IO_GUID = '{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}';

    private const MAX_CONTEXT_POINTS = 300;
    private const MS_PER_MINUTE = 60000;

    public function Create(): void {
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyInteger('PowerVariableID', 0);
        $this->RegisterPropertyInteger('AnalysisInterval', 10); // in Minuten
        $this->RegisterPropertyFloat('StartThreshold', 100.0);

        // Variablen
        $vid = @$this->GetIDForIdent('Status');
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== 3) {
            $this->UnregisterVariable('Status');
        }
        $this->RegisterVariableInteger('Status', 'Status', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 1);
        $this->RegisterVariableString('CurrentPhase', 'Aktuelle Phase', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Script'], 2);
        $this->RegisterVariableInteger('ActiveSince', 'Aktiv Seit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'         => 'Clock'
        ], 3);
        $this->RegisterVariableString('LastGeminiPrompt', 'Letzter KI Prompt', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 4);
        $this->RegisterVariableString('LastGeminiResponse', 'Letzte KI Antwort', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 5);
        $this->RegisterVariableInteger('RemainingTime', 'Restlaufzeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Clock',
            'SUFFIX'       => ' Sek'
        ], 6);
        $this->RegisterVariableInteger('ExpectedEnd', 'Erwartetes Ende', [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'         => 'Clock'
        ], 7);
        $this->RegisterVariableInteger('Progress', 'Fortschritt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Motion',
            'SUFFIX'       => '%',
            'MIN'          => 0,
            'MAX'          => 100
        ], 8);

        // Timer
        $this->RegisterTimer('DataCollectorTimer', 0, 'IDW_CollectData($_IPS[\'TARGET\']);');
        $this->RegisterTimer('AnalysisTimer', 0, 'IDW_AnalyzeData($_IPS[\'TARGET\']);');

        $this->RegisterVariableString('SessionData', 'Session Data (Intern)', '', 99);
        IPS_SetHidden($this->GetIDForIdent('SessionData'), true);

        $this->RegisterVariableString('LastSessionData', 'Letzte Session Data (Intern)', '', 100);
        IPS_SetHidden($this->GetIDForIdent('LastSessionData'), true);

        // Vestaboard: Kurzzusammenfassung für VestaboardGenerator
        $this->RegisterVariableString('VestaboardMessage', 'Vestaboard Nachricht', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Script'], 101);
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();

        IPS_SetDisabled($this->GetIDForIdent('Status'), true);

        $powerVarID = $this->ReadPropertyInteger('PowerVariableID');
        if ($powerVarID > 1 && @IPS_ObjectExists($powerVarID)) {
            $this->RegisterReference($powerVarID);
            $this->RegisterMessage($powerVarID, VM_UPDATE);
        }

        $statusIntervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 1,
                'ConstantActive' => true, 'ConstantValue' => 'Aus',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Information',
                'ColorActive' => true, 'ColorValue' => -1,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 1, 'IntervalMaxValue' => 2,
                'ConstantActive' => true, 'ConstantValue' => 'Start',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Information',
                'ColorActive' => true, 'ColorValue' => 0x0088FF,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 2, 'IntervalMaxValue' => 3,
                'ConstantActive' => true, 'ConstantValue' => 'Aktiv',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Information',
                'ColorActive' => true, 'ColorValue' => 0x00CC00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 3, 'IntervalMaxValue' => 4,
                'ConstantActive' => true, 'ConstantValue' => 'Fertig',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'Information',
                'ColorActive' => true, 'ColorValue' => 0xFFA500,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ]
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Status'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Information',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $statusIntervals
        ]);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('Status'), '');

        if (IPS_VariableProfileExists('Dishwasher.Status')) {
            IPS_DeleteVariableProfile('Dishwasher.Status');
        }

        $this->MaintainTimer();
    }

    public function RequestAction(string $Ident, mixed $Value): void {
        if ($Ident === 'Status') {
            if ($Value === 0 || $Value === 'Aus') {
                $this->SetValue('Status', 0);
                $this->SetValue('CurrentPhase', 'Aus');
                $this->SetValue('RemainingTime', 0);
                $this->SetValue('ExpectedEnd', 0);
                $this->SetValue('Progress', 0);
                $this->SetValue('SessionData', '[]');
                $this->SetValue('VestaboardMessage', '');
                $this->MaintainTimer();
            } else {
                $this->SetValue('Status', (int)$Value);
                $this->MaintainTimer();
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {
        if ($Message == VM_UPDATE) {
            $powerVarID = $this->ReadPropertyInteger('PowerVariableID');
            if ($SenderID == $powerVarID) {
                $power     = $Data[0];
                $status    = $this->GetValue('Status');
                $threshold = $this->ReadPropertyFloat('StartThreshold');

                if ($power > $threshold && ($status === 0 || $status === '' || $status === 3)) {
                    $this->SetValue('Status', 1);
                    $this->SetValue('VestaboardMessage', 'Spülmaschine gestartet…');
                    $this->SetValue('ActiveSince', time());
                    $this->SetValue('CurrentPhase', 'Gestartet');
                    $this->SetValue('RemainingTime', 0);
                    $this->SetValue('ExpectedEnd', 0);
                    $this->SetValue('Progress', 0);
                    $this->SetValue('SessionData', '[]');
                    $this->SLog('INFO', 'Spülmaschine hat gestartet.');
                    $this->MaintainTimer();
                }
            }
        }
    }

    private function MaintainTimer(): void {
        $status = $this->GetValue('Status');
        if ($status === 1 || $status === 2 || $status === 'Start' || $status === 'Aktiv') {
            $this->SetTimerInterval('DataCollectorTimer', self::MS_PER_MINUTE);
            $interval = $this->ReadPropertyInteger('AnalysisInterval');
            $this->SetTimerInterval('AnalysisTimer', $interval * self::MS_PER_MINUTE);
        } else {
            $this->SetTimerInterval('DataCollectorTimer', 0);
            $this->SetTimerInterval('AnalysisTimer', 0);
        }
    }

    public function CollectData(): void {
        $powerVarID = $this->ReadPropertyInteger('PowerVariableID');
        if ($powerVarID == 0 || !IPS_VariableExists($powerVarID)) return;

        $power = round(GetValue($powerVarID));

        $sessionDataStr = $this->GetValue('SessionData');
        $sessionData = json_decode($sessionDataStr, true);
        if (!is_array($sessionData)) $sessionData = [];

        $sessionData[] = $power;
        $this->SetValue('SessionData', json_encode($sessionData));
    }

    public function AnalyzeData(): void {
        // SmartGeminiIO auto-discover
        $geminiInstances = IPS_GetInstanceListByModuleID(self::GEMINI_IO_GUID);
        if (empty($geminiInstances)) {
            $this->SLog('ERROR', 'SmartGeminiIO Instanz nicht gefunden! Bitte eine erstellen.');
            return;
        }
        $geminiId = $geminiInstances[0];

        $sessionDataStr = $this->GetValue('SessionData');
        $sessionData    = json_decode($sessionDataStr, true);
        if (!is_array($sessionData) || count($sessionData) == 0) return;

        // Maximal 300 Punkte (Context Limit)
        if (count($sessionData) > self::MAX_CONTEXT_POINTS) {
            $sessionData = array_slice($sessionData, -self::MAX_CONTEXT_POINTS);
        }

        $dataString = implode(', ', $sessionData);
        $threshold  = $this->ReadPropertyFloat('StartThreshold');

        $systemInstruction = 'Du antwortest ausschließlich im JSON-Format.';

        $userPrompt = "Du bist eine KI zur Analyse des Stromverbrauchs von Haushaltsgeräten.\n";
        $userPrompt .= "Dies ist der Stromverbrauch (in Watt) einer Imperial GSI 8265 BS Spülmaschine.\n";

        $lastSessionDataStr = $this->GetValue('LastSessionData');
        $lastSessionData    = json_decode($lastSessionDataStr, true);
        if (is_array($lastSessionData) && count($lastSessionData) > 0) {
            $lastDuration   = count($lastSessionData);
            $lastDataString = implode(', ', $lastSessionData);
            $userPrompt .= "Als Referenz: Hier ist der komplette Stromverlauf des zuletzt durchgelaufenen Waschvorgangs (Dauer: $lastDuration Minuten):\n";
            $userPrompt .= "[$lastDataString]\n\n";
            $userPrompt .= "Nutze diese Referenzkurve, um besser abzuschätzen, in welcher Phase sich das aktuelle Programm befindet.\n\n";
        }

        $userPrompt .= "Daten des AKTUELLEN Programms (Minutentakt seit Start):\n[$dataString]\n\n";
        $userPrompt .= "HINWEIS STANDBY: Werte unter {$threshold}W sind der Standby-Verbrauch (ausgeschaltete Maschine).\n";
        $userPrompt .= "Deine Aufgabe:\n";
        $userPrompt .= "1. Bestimme die aktuelle Phase (z.B. 'Aufheizen', 'Hauptwäsche', 'Trocknen', 'Fertig').\n";
        $userPrompt .= "2. Entscheide ob das Programm fertig ist (isFinished: true).\n";
        $userPrompt .= "3. Schätze die verbleibende Restlaufzeit in Minuten.\n";

        $responseSchema = json_encode([
            'type'       => 'OBJECT',
            'properties' => [
                'phase'            => ['type' => 'STRING',  'description' => 'Aktuelle Phase des Spülvorgangs'],
                'isFinished'       => ['type' => 'BOOLEAN', 'description' => 'true wenn komplett fertig'],
                'remainingMinutes' => ['type' => 'INTEGER', 'description' => 'Geschätzte Restlaufzeit in Minuten (0 wenn fertig)']
            ],
            'required' => ['phase', 'isFinished', 'remainingMinutes']
        ]);

        $this->SetValue('LastGeminiPrompt', $userPrompt);

        $instanceId = $this->InstanceID;

        // Async via IPS_RunScriptText — GIO_Query blockiert, daher in Background
        $script = '<?php
            $result = GIO_Query(' . $geminiId . ',
                ' . var_export($userPrompt, true) . ',
                ' . var_export($systemInstruction, true) . ',
                ' . var_export($responseSchema, true) . ',
                0.1
            );
            IDW_ProcessGeminiResult(' . $instanceId . ', $result);
        ';
        IPS_RunScriptText($script);
    }

    /**
     * Verarbeitet das Ergebnis der Gemini-Analyse.
     * Wird aus dem Background-Script via IPS_RunScriptText aufgerufen.
     *
     * @param string $jsonText Bereits extrahierter JSON-Text von GIO_Query
     */
    public function ProcessGeminiResult(string $jsonText): void {
        $this->SetValue('LastGeminiResponse', $jsonText);

        if (empty($jsonText)) {
            $this->SLog('ERROR', 'Gemini-Analyse fehlgeschlagen (leere Antwort von SmartGeminiIO).');
            return;
        }

        $parsed = json_decode($jsonText, true);
        if (!is_array($parsed) || !isset($parsed['phase'])) {
            $this->SLog('ERROR', 'Gemini-Antwort konnte nicht geparst werden', 'JSON Error: ' . json_last_error_msg() . ' | Response: ' . $jsonText);
            return;
        }

        $this->SetValue('CurrentPhase', $parsed['phase']);

        if (isset($parsed['remainingMinutes'])) {
            $remMin = (int)$parsed['remainingMinutes'];
            $remSec = $remMin * 60;
            $this->SetValue('RemainingTime', $remSec);

            // Vestaboard: Kurz-Status mit Restzeit
            if ($remMin > 0) {
                $this->SetValue('VestaboardMessage', 'Spülmaschine: ' . $parsed['phase'] . ' (~' . $remMin . ' min)');
            }

            if ($remSec > 0) {
                $expectedEnd = time() + $remSec;
                $this->SetValue('ExpectedEnd', $expectedEnd);

                $activeSince = $this->GetValue('ActiveSince');
                $total       = $expectedEnd - $activeSince;
                if ($total > 0) {
                    $progress = (int)(((time() - $activeSince) / $total) * 100);
                    $this->SetValue('Progress', min(100, max(0, $progress)));
                }
            } else {
                $this->SetValue('Progress', 100);
                $this->SetValue('ExpectedEnd', time());
            }
        }

        if (isset($parsed['isFinished']) && $parsed['isFinished'] == true) {
            $this->SetValue('Status', 3);
            $this->SetValue('Progress', 0);
            $this->SetValue('VestaboardMessage', 'Spülmaschine fertig! Bitte ausräumen.');

            // Komplette Kurve für nächsten Durchlauf speichern
            $this->SetValue('LastSessionData', $this->GetValue('SessionData'));
            $this->MaintainTimer();
            $this->SLog('INFO', 'Gemini meldet: Spülmaschine ist fertig.');
        } else {
            $this->SLog('INFO', 'Gemini Phase: ' . $parsed['phase']);
        }
    }

    
}
