<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';

class VestaboardGenerator extends IPSModuleStrict {
    use SmartLog_Trait;

    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;
    use SmartHttp_Trait;

    public function Create(): void {
        parent::Create();
        $this->DA_RegisterAvailability(900);
        
        $this->RegisterPropertyString("VariablesList", "[]");
        $this->RegisterPropertyString("ApiUrl", "http://<IP-ADRESSE>:7000/local-api/message");
        $this->RegisterPropertyString("ApiKey", "");
        $this->RegisterPropertyInteger("ManualUpdateTriggerID", 0); // Trigger für manuelles Update
        $this->RegisterPropertyInteger("ActiveViewVariableID", 0); // Trigger für Multi-View
        $this->RegisterPropertyInteger("HeimkinoModeVariableID", 0); // Veraltet
        $this->RegisterPropertyString("HeimkinoModeValues", "3"); // Die IDs des Heimkino-Modus (kommagetrennt)
        $this->RegisterPropertyInteger("AbsenceModeVariableID", 0); // Veraltet
        $this->RegisterPropertyString("AbsenceModeValues", "1");
        $this->RegisterPropertyInteger("ActiveTimeStart", 7);
        $this->RegisterPropertyInteger("ActiveTimeEnd", 22);
        $this->RegisterPropertyInteger("UpdateDelaySeconds", 60); // Muss für Abwärtskompatibilität bleiben
        $this->RegisterPropertyInteger("UpdateDelayMinutes", 1);
        $this->RegisterPropertyString("SleepText", "");

        $this->RegisterTimer("VestaboardUpdateTimer", 0, 'VESTAG_UpdateBoard($_IPS[\'TARGET\'], false);');
        $this->RegisterTimer("VestaboardSleepTimer", 0, 'VESTAG_SendSleepText($_IPS[\'TARGET\']);');
        $this->RegisterTimer("VestaboardWakeupTimer", 0, 'VESTAG_Wakeup($_IPS[\'TARGET\']);');

        for ($i = 1; $i <= 6; $i++) {
            $this->RegisterVariableString("Line{$i}", "Zeile {$i}", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'list'], $i);
        }
    }

    public function Destroy(): void {
        parent::Destroy();
        }

    public function ApplyChanges(): void {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        // Removed VestaboardLocal reference
        $ref_ManualUpdateTriggerID = $this->ReadPropertyInteger('ManualUpdateTriggerID');
        if ($ref_ManualUpdateTriggerID > 1 && @IPS_ObjectExists($ref_ManualUpdateTriggerID)) {
            $this->RegisterReference($ref_ManualUpdateTriggerID);
        }
        $ref_ActiveViewVariableID = $this->ReadPropertyInteger('ActiveViewVariableID');
        if ($ref_ActiveViewVariableID > 1 && @IPS_ObjectExists($ref_ActiveViewVariableID)) {
            $this->RegisterReference($ref_ActiveViewVariableID);
        }

        $list_VariablesList = json_decode($this->ReadPropertyString('VariablesList'), true);
        if (is_array($list_VariablesList)) {
            foreach ($list_VariablesList as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------


        
        // Alte Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        
        // Auf Variablen-Updates lauschen (MessageSink)
        $list = json_decode($this->ReadPropertyString("VariablesList"), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                if ($row['Active'] && $row['VariableID'] > 0 && IPS_VariableExists($row['VariableID'])) {
                    $this->RegisterMessage($row['VariableID'], VM_UPDATE);
                }
            }
        }
        
        $triggerId = $this->ReadPropertyInteger("ManualUpdateTriggerID");
        if ($triggerId > 0 && IPS_VariableExists($triggerId)) {
            $this->RegisterMessage($triggerId, VM_UPDATE);
        }
        
        $activeViewId = $this->ReadPropertyInteger("ActiveViewVariableID");
        if ($activeViewId > 0 && IPS_VariableExists($activeViewId)) {
            $this->RegisterMessage($activeViewId, VM_UPDATE);
        }
        
        
        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);

        
        $this->UpdateSleepTimer();
        $this->UpdateWakeupTimer();

    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {
        $triggerId = $this->ReadPropertyInteger("ManualUpdateTriggerID");
        if ($triggerId > 0 && $SenderID == $triggerId) {
            $this->DoUpdateBoard(true); // Manuelles Update erzwingen
            return;
        }

        $activeViewId = $this->ReadPropertyInteger("ActiveViewVariableID");
        if ($activeViewId > 0 && $SenderID == $activeViewId) {
            $this->DoUpdateBoard(true); // Ansicht wurde gewechselt
            return;
        }

        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;
        
        $isImmediate = false;
        $list = json_decode($this->ReadPropertyString("VariablesList"), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                if ($row['Active'] && $row['VariableID'] == $SenderID) {
                    if (isset($row['Priority']) && $row['Priority'] === 'immediate') {
                        $isImmediate = true;
                    }
                    break;
                }
            }
        }
        
        if ($isImmediate) {
            $this->DoUpdateBoard();
        }
        // Keine automatischen Updates mehr für Infos (High/Low)

    }

    public function UpdateBoard(bool $force = false): void {
        $this->DoUpdateBoard($force, false);
    }

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        $isAbsent = !$this->IsHome();
        $isHeimkinoActive = $this->IsCinema();
        $forceUpdate = (!$isAbsent || $isHeimkinoActive);
        $this->DoUpdateBoard($forceUpdate, !$isHeimkinoActive);
    }

    /**
     * Sendet eine dringende Nachricht sofort auf das Board (z.B. Alarm-Meldungen).
     * Umgeht das View- und Prioritätssystem — direkte Ausgabe.
     * Danach wird das Board nach dem konfigurierten Delay wieder normal aktualisiert.
     *
     * @param string $text    Der anzuzeigende Text (max. 6 Zeilen, je 22 Zeichen)
     * @param bool   $resume  Nach Alarm-Anzeige das normale Board wieder herstellen (Standard: false)
     */
    public function PushAlert(string $text, bool $resume = false): void {
        if ($this->IsSleeping()) {
            $this->SLogInfo("PushAlert: Schlafmodus aktiv. Nachricht wird nicht gesendet.");
            return;
        }

        $this->SLogInfo("PushAlert: Sende Alarm-Nachricht direkt auf Board.");
        try {
            $this->SendTextToBoard($text);
        } catch (Exception $e) {
            $this->SLogInfo("PushAlert: Fehler beim Senden: " . $e->getMessage());
        }

        // Optional: normalen Board-Inhalt nach kurzer Zeit wiederherstellen
        if ($resume) {
            $this->SetTimerInterval('VestaboardUpdateTimer', 30 * 1000); // 30s später wieder normal
        }
    }

    private function DoUpdateBoard(bool $force = false, bool $isHeimkinoTurningOff = false): void {
        $this->SetTimerInterval('VestaboardUpdateTimer', 0);
        
        if ($this->IsSleeping()) {
            $this->SLogInfo("DoUpdateBoard: Schlafmodus aktiv. Kein Update.");
            return;
        }
        
        if ($this->IsCinema()) {
            $this->UpdateBoardForHeimkino($force);
            return;
        }
        
        $linesImmediate = [];
        $linesHigh = [];
        $linesLow = [];

        $list = json_decode($this->ReadPropertyString("VariablesList"), true);
        if (!is_array($list)) {
            $list = [];
        }

        $activeViewId = $this->ReadPropertyInteger("ActiveViewVariableID");
        $currentView = 1;
        if ($activeViewId > 0 && IPS_VariableExists($activeViewId)) {
            $currentView = (int)GetValue($activeViewId);
            if ($currentView < 1) $currentView = 1;
        }

        foreach ($list as $row) {
            if (!$row['Active'] || $row['VariableID'] == 0) {
                continue;
            }
            
            $rowView = isset($row['View']) ? (int)$row['View'] : 1;
            if ($rowView !== $currentView) {
                continue;
            }
            
            $id = $row['VariableID'];
            $type = $row['Type'];
            $prio = isset($row['Priority']) ? $row['Priority'] : 'low';
            $format = isset($row['FormatString']) ? $row['FormatString'] : '';
            
            $text = $this->GetLineText($type, $id, $format);
            $cleanText = trim(preg_replace('/\{\d{1,2}\}/', '', $text));

            if ($cleanText !== "") {
                if ($prio === 'immediate') {
                    $linesImmediate[] = ["text"=> $text, "clean"=> $cleanText];
                } elseif ($prio === 'high') {
                    $linesHigh[] = ["text"=> $text, "clean"=> $cleanText];
                } else {
                    $linesLow[] = ["text"=> $text, "clean"=> $cleanText];
                }
            }
        }

        // Alle 'Sofort'und 'Hoch'Prioritäten einfügen
        $finalLines = array_merge($linesImmediate, $linesHigh);

        // Wenn noch Platz ist, fülle mit 'Niedrig'auf. 
        // Das Vestaboard hat genau 6 nutzbare Zeilen.
        $remainingSpace = 6 - count($finalLines);
        if ($remainingSpace > 0) {
            $finalLines = array_merge($finalLines, array_slice($linesLow, 0, $remainingSpace));
        }

        // Maximal 6 Zeilen extrahieren
        $finalLines = array_slice($finalLines, 0, 6);

        // String zusammenbauen und Variablen updaten
        $textBasis = "";
        for ($i = 0; $i < 6; $i++) {
            if (isset($finalLines[$i])) {
                $textBasis .= $finalLines[$i]['text'] . "\n";
                $this->SetValue("Line". ($i + 1), $finalLines[$i]['clean']);
            } else {
                $this->SetValue("Line". ($i + 1), "");
            }
        }
        $textBasis = rtrim($textBasis, "\n"); // Letzten Zeilenumbruch entfernen
        
        $activeStart = $this->ReadPropertyInteger("ActiveTimeStart");
        $activeEnd = $this->ReadPropertyInteger("ActiveTimeEnd");
        $currentHour = (int)date('G');

        $isActiveTime = true;
        if ($activeStart != $activeEnd) {
            if ($activeStart < $activeEnd) {
                if ($currentHour < $activeStart || $currentHour >= $activeEnd) {
                    $isActiveTime = false;
                }
            } else {
                if ($currentHour >= $activeEnd && $currentHour < $activeStart) {
                    $isActiveTime = false;
                }
            }
        }

        $isAbsent = !$this->IsHome();

        if ($isAbsent) {
            $this->SLogInfo('VestaboardGenerator: Aktualisierung uebersprungen (Haus im Abwesenheitsmodus)');
        } elseif ($isActiveTime || $force) {
            // Direkt ans Board senden
            $this->SendTextToBoard($textBasis);
        } else {
            $sleepText = $this->ReadPropertyString("SleepText");
            if ($isHeimkinoTurningOff && $sleepText !== "") {
                $sleepText = $this->SanitizeTextForVestaboard($sleepText);
                $this->SendTextToBoard($sleepText);
            } else {
                $this->SLogInfo('VestaboardGenerator: '. "Aktualisierung uebersprungen (Ruhezeit aktiv: ". $currentHour . "Uhr)");
            }
        }
    }

    private function UpdateBoardForHeimkino(bool $force): void {
        $textBasis = "Heimkino Aktiv!";
        
        for ($i = 1; $i <= 6; $i++) {
            if ($i == 1) {
                $this->SetValue("Line1", $textBasis);
            } else {
                $this->SetValue("Line{$i}", "");
            }
        }
        
        $this->SendTextToBoard($this->PadToRight($textBasis, ""));
    }

    private function GetLineText(string $type, int $id, string $format): string {
        $text = "";
        
        if ($type !== 'text'&& $type !== 'empty') {
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return "";
            }
        }
        
        switch ($type) {
            case 'alert':
                $val = GetValue($id);
                $isActive = (is_bool($val) && $val) || ((is_int($val) || is_float($val)) && $val > 0);
                if ($isActive && $format != "") {
                    $text = $this->PadToRight($format, "");
                }
                break;
            case 'wm':
            case 'tr':
                $prozent = max(0, min(100, (int)GetValue($id)));
                if ($prozent > 0) {
                    $prefix = ($type === 'tr') ? "TR": "WM";
                    $color = ($type === 'tr') ? "{67}": "{68}";
                    
                    if ($format != "") {
                        if (preg_match('/\{\d{1,2}\}/', $format, $matches)) {
                            $color = $matches[0];
                            $prefix = ltrim(str_replace($color, "", $format));
                        } else {
                            $prefix = ltrim($format);
                        }
                    }
                    
                    $text = $this->GenerateProgressBar($prefix, $prozent, $color);
                }
                break;
            case 'aussen':
                $temp = (float)GetValue($id);
                $color = "{69}"; // Weiß (Neutral)
                if ($temp < 0) $color = "{67}"; // Blau (Kalt)
                if ($temp > 25) $color = "{63}"; // Rot (Warm)
                
                $format = ltrim($format);
                if ($format != "") {
                    if (strpos($format, '%s') !== false || strpos($format, '%f') !== false) {
                        $textStr = sprintf($format, round($temp, 1));
                    } else {
                        // Wenn sie z.B. nur "Pool: "eingegeben haben
                        $spacer = (!empty($format) && substr($format, -1) !== ' ') ? ' ' : '';
                        $textStr = $format . $spacer . round($temp, 1) . "{62}C";
                    }
                } else {
                    // Standardausgabe nur die Zahl + C
                    $textStr = round($temp, 1) . "{62}C";
                }
                
                $text = $this->PadToRight($textStr, $color);
                break;
            case 'trash':
                $val = GetValue($id);
                $days = -1;
                $isActive = false;
                $isStringVal = false;

                if (is_bool($val)) {
                    $isActive = $val;
                    $days = 0; // Heute
                } else if (is_int($val) || is_float($val)) {
                    // Profile value? Wenn die Variable ein Profil hat, liefert IPSymcon evtl einen int.
                    // Falls es sich um "Tage"handelt:
                    $days = (int)$val;
                    if ($days <= 2) { 
                        $isActive = true;
                    }
                } else if (is_string($val)) {
                    $val = trim($val);
                    if ($val !== "") {
                        $isActive = true;
                        $isStringVal = true;
                    }
                }

                if ($isActive) {
                    $color = "{65}"; // Standard Gelb
                    
                    if ($isStringVal) {
                        $prefix = (string)$val;
                        // Automatische Farberkennung anhand des Namens
                        if (stripos($prefix, 'bio') !== false) {
                            $color = "{66}"; // Grün
                        } else if (stripos($prefix, 'papier') !== false) {
                            $color = "{67}"; // Blau
                        } else if (stripos($prefix, 'rest') !== false) {
                            $color = "{70}"; // Schwarz
                        } else if (stripos($prefix, 'gelb') !== false) {
                            $color = "{65}"; // Gelb
                        }
                    } else {
                        $prefix = "Müll";
                    }

                    $format = ltrim($format);
                    // Format überschreibt Farbe oder hängt was an
                    if ($format != "") {
                        if (preg_match('/\{\d{1,2}\}/', $format, $matches)) {
                            $color = $matches[0];
                            $format = ltrim(str_replace($color, "", $format));
                        }
                        if ($format != "") {
                            if ($isStringVal) {
                                // "Morgen: Bio"
                                $spacer = (!empty($format) && substr($format, -1) !== ' ') ? ' ' : '';
                                $prefix = $format . $spacer . $prefix;
                            } else {
                                $prefix = $format;
                            }
                        }
                    }

                    $suffix = "";
                    if (!$isStringVal) {
                        if ($days === 0) {
                            $suffix = "Heute!";
                        } else if ($days === 1) {
                            $suffix = "Morgen";
                        } else if ($days === 2) {
                            $suffix = "in 2 Tagen";
                        }
                    }

                    $text = $this->PadToRight(trim($prefix . $suffix), $color);
                }
                break;
            case 'custom':
                $val = GetValue($id);
                if (is_bool($val)) {
                    $val = $val ? 'Ein': 'Aus';
                } else {
                    $val = trim((string)$val);
                }
                
                if ($val === "") {
                    return "";
                }
                
                $format = ltrim($format);
                if ($format != "") {
                    if (strpos($format, '%s') !== false || strpos($format, '%d') !== false || strpos($format, '%f') !== false) {
                        $text = sprintf($format, $val);
                    } else {
                        $spacer = (!empty($format) && substr($format, -1) !== ' ' && substr($val, 0, 1) !== ' ') ? ' ' : '';
                        $text = $format . $spacer . $val;
                    }
                } else {
                    $text = $val;
                }
                // Die Formatierung (inkl. Längenbegrenzung) anwenden. 
                // Farbcodes im Text werden durch GetVisualLength in PadToRight korrekt behandelt.
                $text = $this->PadToRight($text, "");
                break;
        }
        return $text;
    }

    private function GetVisualLength(string $text): int {
        $visualLength = mb_strlen($text, 'UTF-8');
        if (preg_match_all('/\{\d{1,2}\}/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $visualLength -= mb_strlen($match, 'UTF-8'); 
                $visualLength += 1; 
            }
        }
        return $visualLength;
    }
    private function SanitizeTextForVestaboard(string $text): string {
        $search = ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'];
        $replace = ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'];
        return str_replace($search, $replace, $text);
    }

    private function PadToRight(string $leftText, string $rightIcon = ""): string {
        $leftText = $this->SanitizeTextForVestaboard($leftText);
        // Smart-Extraktion: Wenn das rechte Icon leer ist, aber der User am Ende des Textes
        // einen Farbcode (z.B. {66}) angegeben hat, ziehen wir diesen automatisch nach ganz rechts.
        if ($rightIcon === ""&& preg_match('/\s*(\{\d{1,2}\})\s*$/', $leftText, $matches)) {
            $rightIcon = $matches[1];
            $leftText = preg_replace('/\s*\{\d{1,2}\}\s*$/', '', $leftText);
        }
        
        $leftLen = $this->GetVisualLength($leftText);
        $rightLen = $this->GetVisualLength($rightIcon);
        
        if ($leftLen + $rightLen > 22) {
            // Kürzen
            $allowedLen = 22 - $rightLen;
            $leftText = mb_substr($leftText, 0, $allowedLen, 'UTF-8'); 
            $leftLen = $this->GetVisualLength($leftText);
        }
        
        $spacesNeeded = 22 - $leftLen - $rightLen;
        return $leftText . str_repeat(" ", max(0, $spacesNeeded)) . $rightIcon;
    }

    private function GenerateProgressBar(string $prefix, int $prozent, string $defaultColor): string {
        $prefix = $this->SanitizeTextForVestaboard($prefix);
        $colorCode = ($prozent >= 100) ? "{66}": $defaultColor;
        
        $suffix = sprintf("%d%% ", $prozent);
        $prefixLen = mb_strlen($prefix, 'UTF-8');
        $suffixLen = mb_strlen($suffix, 'UTF-8');
        
        // Mindestens 5 Blöcke wollen wir für den Balken haben (Maximal also 17 Zeichen für Text)
        if ($prefixLen + $suffixLen > 17) {
            $prefix = mb_substr($prefix, 0, 17 - $suffixLen, 'UTF-8');
            $prefixLen = mb_strlen($prefix, 'UTF-8');
        }
        
        $text = $prefix . $suffix;
        $balkenBreite = 22 - ($prefixLen + $suffixLen);
        
        if ($balkenBreite > 0) {
            $gefuellteSpalten = (int)round(($prozent / 100) * $balkenBreite);
            $leereSpalten = $balkenBreite - $gefuellteSpalten;
            
            $balken = str_repeat($colorCode, $gefuellteSpalten) . str_repeat(" ", $leereSpalten);
            return $text . $balken;
        }
        return $text;
    }

    public function SendSleepText(): void {
        $sleepText = $this->ReadPropertyString("SleepText");
        $isAbsent = !$this->IsHome();

        if ($sleepText !== ""&& !$isAbsent) {
            $sleepText = $this->SanitizeTextForVestaboard($sleepText);
            $this->SendTextToBoard($sleepText);
        }
        
        $this->UpdateSleepTimer();
    }

    private function UpdateSleepTimer(): void {
        $activeEnd = $this->ReadPropertyInteger("ActiveTimeEnd");
        $sleepText = $this->ReadPropertyString("SleepText");

        if ($sleepText === "") {
            $this->SetTimerInterval("VestaboardSleepTimer", 0);
            return;
        }

        $now = time();
        // mktime(hour, minute, second, month, day, year)
        $targetTime = mktime($activeEnd, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'));

        if ($targetTime <= $now) {
            // Wenn die Zeit für heute schon vorbei ist, auf morgen setzen
            $targetTime = strtotime('+1 day', $targetTime);
        }

        $interval = (int)(($targetTime - $now) * 1000);
        $this->SetTimerInterval("VestaboardSleepTimer", $interval);
    }

    public function Wakeup(): void {
        $this->DoUpdateBoard(true);
        $this->UpdateWakeupTimer();
    }

    private function UpdateWakeupTimer(): void {
        $activeStart = $this->ReadPropertyInteger("ActiveTimeStart");

        $now = time();
        $targetTime = mktime($activeStart, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'));

        if ($targetTime <= $now) {
            $targetTime = strtotime('+1 day', $targetTime);
        }

        $interval = (int)(($targetTime - $now) * 1000);
        $this->SetTimerInterval("VestaboardWakeupTimer", $interval);
    }

    public function SendMessage(string $text): void {
        $this->SendTextToBoard($text);
    }

    private function SendTextToBoard(string $text): void {
        $layout = $this->ConvertTextToArray($text);
        
        $ip = $this->ReadPropertyString('ApiUrl');
        $token = $this->ReadPropertyString('ApiKey');

        if (empty($ip) || strpos($ip, '<IP-ADRESSE>') !== false || empty($token)) {
            $this->DA_SetAvailable(false, 'Missing IP or Token');
            return;
        }

        $headers = [
            'X-Vestaboard-Local-Api-Key: ' . $token
        ];

        // Wir nutzen den SmartHttp_Trait für Logging und stabileren cURL Aufbau (wie im alten Local-Modul)
        $response = $this->HttpRequest($ip, 'POST', $headers, $layout, 10, false);

        if ($response === null) {
            $this->DA_SetAvailable(false, 'Lokale API nicht erreichbar oder HTTP Fehler');
        } else {
            $this->DA_SetAvailable(true);
        }
    }

    private function ConvertTextToArray(string $text): array {
        $lines = explode("\n", $text);
        $layout = array_fill(0, 6, array_fill(0, 22, 0));
        
        $charMap = [
            ' ' => 0,
            '!' => 37, '@' => 38, '#' => 39, '$' => 40, '(' => 41, ')' => 42,
            '-' => 44, '+' => 46, '&' => 47, '=' => 48, ';' => 49, ':' => 50,
            '\'' => 52, '"' => 53, '%' => 54, ',' => 55, '.' => 56, '/' => 59,
            '?' => 60, '°' => 62
        ];

        $parsedLines = [];
        foreach ($lines as $line) {
            $tokens = [];
            $parts = preg_split('/(\{[\d]+\})/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            
            foreach ($parts as $part) {
                if (preg_match('/^\{(\d+)\}$/', $part, $matches)) {
                    $tokens[] = (int)$matches[1];
                } else {
                    $chars = mb_str_split($part, 1, 'UTF-8');
                    foreach ($chars as $char) {
                        $char = mb_strtoupper($char, 'UTF-8');
                        if (preg_match('/^[A-Z]$/', $char)) {
                            $tokens[] = ord($char) - ord('A') + 1;
                        } elseif (preg_match('/^[1-9]$/', $char)) {
                            $tokens[] = (int)$char + 26;
                        } elseif ($char === '0') {
                            $tokens[] = 36;
                        } elseif (isset($charMap[$char])) {
                            $tokens[] = $charMap[$char];
                        } elseif ($char === 'Ä') {
                            $tokens[] = ord('A') - ord('A') + 1;
                            $tokens[] = ord('E') - ord('A') + 1;
                        } elseif ($char === 'Ö') {
                            $tokens[] = ord('O') - ord('A') + 1;
                            $tokens[] = ord('E') - ord('A') + 1;
                        } elseif ($char === 'Ü') {
                            $tokens[] = ord('U') - ord('A') + 1;
                            $tokens[] = ord('E') - ord('A') + 1;
                        } elseif ($char === 'ß') {
                            $tokens[] = ord('S') - ord('A') + 1;
                            $tokens[] = ord('S') - ord('A') + 1;
                        } else {
                            $tokens[] = 0; 
                        }
                    }
                }
            }
            
            if (empty($tokens)) {
                $parsedLines[] = [];
            } else {
                $chunks = array_chunk($tokens, 22);
                foreach ($chunks as $chunk) {
                    $parsedLines[] = $chunk;
                }
            }
        }
        
        $maxLines = min(6, count($parsedLines));
        
        for ($r = 0; $r < $maxLines; $r++) {
            $rowTokens = $parsedLines[$r];
            $tokenCount = count($rowTokens);
            
            for ($c = 0; $c < $tokenCount; $c++) {
                if ($c < 22) {
                    $layout[$r][$c] = $rowTokens[$c];
                }
            }
        }

        return $layout;
    }

    public function GetConfigurationForm(): string
    {
        $json = <<<'EOT'
{
    "elements": [

        {
            "type": "Label",
            "label": "Hier legst du fest, welche Variablen auf deinem Board angezeigt werden sollen. Weise ihnen Ansichten, Prioritäten und Formate zu."
        },
        {
            "type": "List",
            "name": "VariablesList",
            "caption": "Variablen Zuordnung",
            "rowCount": 15,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "name": "View",
                    "caption": "Ansicht",
                    "type": "Select",
                    "options": [
                        {"label": "Ansicht 1", "value": 1},
                        {"label": "Ansicht 2", "value": 2},
                        {"label": "Ansicht 3", "value": 3},
                        {"label": "Ansicht 4", "value": 4},
                        {"label": "Ansicht 5", "value": 5},
                        {"label": "Ansicht 6", "value": 6}
                    ],
                    "width": "100px",
                    "add": 1,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {"label": "Ansicht 1", "value": 1},
                            {"label": "Ansicht 2", "value": 2},
                            {"label": "Ansicht 3", "value": 3},
                            {"label": "Ansicht 4", "value": 4},
                            {"label": "Ansicht 5", "value": 5},
                            {"label": "Ansicht 6", "value": 6}
                        ]
                    }
                },
                {
                    "name": "Priority",
                    "caption": "Prio",
                    "type": "Select",
                    "options": [
                        {
                            "label": "Sofort",
                            "value": "immediate"
                        },
                        {
                            "label": "Hoch",
                            "value": "high"
                        },
                        {
                            "label": "Niedrig",
                            "value": "low"
                        }
                    ],
                    "width": "100px",
                    "add": "low",
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "label": "Sofort",
                                "value": "immediate"
                            },
                            {
                                "label": "Hoch",
                                "value": "high"
                            },
                            {
                                "label": "Niedrig",
                                "value": "low"
                            }
                        ]
                    }
                },
                {
                    "name": "Active",
                    "caption": "Aktiv",
                    "type": "CheckBox",
                    "width": "80px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "name": "Type",
                    "caption": "Typ",
                    "type": "Select",
                    "options": [
                        {
                            "label": "Benutzerdefiniert (Text)",
                            "value": "custom"
                        },
                        {
                            "label": "Ereignis (Wahr/Falsch)",
                            "value": "alert"
                        },
                        {
                            "label": "Fortschrittsbalken",
                            "value": "wm"
                        },
                        {
                            "label": "Temperatur",
                            "value": "aussen"
                        },
                        {
                            "label": "Müllabfuhr (Tage/Wahr)",
                            "value": "trash"
                        }
                    ],
                    "width": "200px",
                    "add": "custom",
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "label": "Benutzerdefiniert (Text)",
                                "value": "custom"
                            },
                            {
                                "label": "Ereignis (Wahr/Falsch)",
                                "value": "alert"
                            },
                            {
                                "label": "Fortschrittsbalken",
                                "value": "wm"
                            },
                            {
                                "label": "Temperatur",
                                "value": "aussen"
                            },
                            {
                                "label": "Müllabfuhr (Tage/Wahr)",
                                "value": "trash"
                            }
                        ]
                    }
                },
                {
                    "name": "VariableID",
                    "caption": "Variable",
                    "type": "SelectVariable",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "name": "FormatString",
                    "caption": "Format / Prefix (für 'Benutzerdefiniert')",
                    "type": "ValidationTextBox",
                    "width": "250px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Allgemeine Einstellungen",
            "items": [
                {
                    "type": "Label",
                    "label": "Verbindungseinstellungen zum lokalen Vestaboard:"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "ValidationTextBox",
                            "name": "ApiUrl",
                            "caption": "Vestaboard Local API URL"
                        }
                    ]
                },
                {
                    "type": "PasswordTextBox",
                    "name": "ApiKey",
                    "caption": "Local API Key"
                },
                {
                    "type": "Label",
                    "label": " "
                },
                {
                    "type": "Label",
                    "label": "Vestaboard Farbcodes & Sonderzeichen (Info)"
                },
                {
                    "type": "Label",
                    "label": "Farben: {63} Rot | {64} Orange | {65} Gelb | {66} Grün | {67} Blau | {68} Violett | {69} Weiß | {70} Schwarz | {0} Leer"
                },
                {
                    "type": "Label",
                    "label": "Sonderzeichen: {37} ! | {38} @ | {39} # | {40} $ | {41} ( | {42} ) | {44} - | {46} + | {47} & | {48} = | {49} ; | {50} : | {52} ' | {53} \" | {54} % | {55} , | {56} . | {59} / | {60} ? | {62} °"
                },
                {
                    "type": "Label",
                    "caption": "Manueller Trigger"
                },
                {
                    "type": "Label",
                    "label": "Hier kannst du eine Variable (z.B. einen Schalter) auswählen, um das Board sofort zu aktualisieren."
                },
                {
                    "type": "SelectVariable",
                    "name": "ManualUpdateTriggerID",
                    "caption": "Auslöser-Variable (z.B. Button oder Schalter, aktualisiert sofort)"
                },
                {
                    "type": "Label",
                    "caption": "Aktive Ansicht"
                },
                {
                    "type": "Label",
                    "label": "Wähle eine Variable, über die du zwischen den verschiedenen Ansichten (1 bis 6) umschalten kannst."
                },
                {
                    "type": "SelectVariable",
                    "name": "ActiveViewVariableID",
                    "caption": "Variable zur Ansichts-Umschaltung (1 bis 6)"
                },

                {
                    "type": "Label",
                    "caption": "Aktivitäts-Zeitraum (außerhalb dieser Stunden wird nicht gesendet)"
                },
                {
                    "type": "Label",
                    "label": "Stelle hier ein, von wann bis wann das Board aktiv sein soll. Außerhalb dieser Zeiten geht es in den Ruhemodus."
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "ActiveTimeStart",
                            "caption": "Start-Stunde (z.B. 7)",
                            "minimum": 0,
                            "maximum": 23
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "ActiveTimeEnd",
                            "caption": "End-Stunde (z.B. 22)",
                            "minimum": 0,
                            "maximum": 23
                        }
                    ]
                },
                {
                    "type": "ValidationTextBox",
                    "name": "SleepText",
                    "caption": "Abschalttext (wird beim Erreichen der End-Stunde gesendet, falls nicht leer)"
                },
                {
                    "type": "Label",
                    "caption": "Puffer-Zeit für Updates (in Minuten)"
                },
                {
                    "type": "Label",
                    "label": "Damit das Board nicht bei jeder kleinsten Änderung rattert, kannst du hier eine Pufferzeit einstellen. Änderungen werden gesammelt und gemeinsam gesendet."
                },
                {
                    "type": "NumberSpinner",
                    "name": "UpdateDelayMinutes",
                    "caption": "Sammel-Verzögerung für Updates (in Minuten)",
                    "minimum": 0
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Board jetzt manuell aktualisieren",
            "onClick": "VESTAG_UpdateBoard($id, true);"
        }
    ]
}
EOT;
        return $json;
    }
}


?>
