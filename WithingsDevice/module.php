<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class WithingsDevice extends IPSModuleStrict {
    use SmartLog_Trait;

    /** @var array<string, bool> Cache für bereits angelegte Variablen-Idents (innerhalb eines Request-Zyklus) */
    private array $createdIdents = [];

    // --- Withings Measurement Type Constants (Body Scan 2 / Waage) ---
    private const MEASURE_WEIGHT = 1;
    private const MEASURE_HEIGHT = 4;
    private const MEASURE_FAT_FREE_MASS = 5;
    private const MEASURE_FAT_RATIO = 6;
    private const MEASURE_FAT_MASS_WEIGHT = 8;
    private const MEASURE_DIASTOLIC_BP = 9;
    private const MEASURE_SYSTOLIC_BP = 10;
    private const MEASURE_HEART_PULSE = 11;
    private const MEASURE_TEMPERATURE = 12;
    private const MEASURE_SP02 = 54;
    private const MEASURE_BODY_TEMPERATURE = 71;
    private const MEASURE_SKIN_TEMPERATURE = 73;
    private const MEASURE_MUSCLE_MASS = 76;
    private const MEASURE_HYDRATION = 77;
    private const MEASURE_BONE_MASS = 88;
    private const MEASURE_PWV = 91;
    private const MEASURE_VO2_MAX = 123;
    private const MEASURE_VISCERAL_FAT = 130;
    private const MEASURE_VASCULAR_AGE = 135;
    private const MEASURE_NERVE_HEALTH = 136;
    private const MEASURE_QT_INTERVAL = 138;
    private const MEASURE_AFIB = 139;
    private const MEASURE_VASCULAR_AGE_2 = 155;
    private const MEASURE_EXTRACELLULAR_WATER = 168;
    private const MEASURE_INTRACELLULAR_WATER = 169;
    private const MEASURE_FAT_TORSO = 170;
    private const MEASURE_FAT_ARMS = 171;
    private const MEASURE_FAT_LEGS = 172;
    private const MEASURE_FAT_FREE_SEGMENT = 173;
    private const MEASURE_FAT_SEGMENT = 174;
    private const MEASURE_MUSCLE_SEGMENT = 175;
    private const MEASURE_NERVE_SCORE = 196;
    private const MEASURE_NERVE_LEFT_FOOT = 197;
    private const MEASURE_NERVE_RIGHT_FOOT = 198;
    private const MEASURE_BMR = 226;
    private const MEASURE_METABOLIC_AGE = 227;

    public function Create(): void {
        parent::Create();
        
        $this->RegisterPropertyString("ClientID", "");
        $this->RegisterPropertyString("ClientSecret", "");
        $this->RegisterPropertyInteger("FetchInterval", 240);

        // LastUpdate als Attribut — kein ApplyChanges bei Aktualisierung nötig
        $this->RegisterAttributeInteger("LastUpdate", 0);

        // Gemini API-Key und Modell werden zentral über SmartGeminiIO konfiguriert.
        $this->RegisterPropertyInteger("ArchiveDays", 28);
        $this->RegisterPropertyInteger("SMTPInstanceID", 0);
        $this->RegisterPropertyBoolean("EnableAI", false);

        // Versteckte Attribute für OAuth Tokens
        $this->RegisterAttributeString("AccessToken", "");
        $this->RegisterAttributeString("RefreshToken", "");
        $this->RegisterAttributeInteger("TokenExpires", 0);

        $this->RegisterTimer("FetchTimer", 0, 'WITHINGS_FetchMeasurements($_IPS[\'TARGET\']);');

        $this->RegisterVariableString("ConnectionStatus", "Verbindungsstatus", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], -1);
        $this->RegisterVariableString("LastMeasurement", "Letzte Messung", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 0);
        $this->RegisterVariableString("DeviceBattery", "Geräte-Akku", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1);
        $this->RegisterVariableString("DailyReport", "Gemini Analyse", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 99);
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SMTPInstanceID = $this->ReadPropertyInteger('SMTPInstanceID');
        if ($ref_SMTPInstanceID > 1 && @IPS_ObjectExists($ref_SMTPInstanceID)) {
            $this->RegisterReference($ref_SMTPInstanceID);
        }
        // ---------------------------------

        // Migration: Falls LastUpdate noch als alte Property existiert, Wert übernehmen
        $oldPropValue = @json_decode(@IPS_GetConfiguration($this->InstanceID), true);
        if (is_array($oldPropValue) && isset($oldPropValue['LastUpdate']) && $oldPropValue['LastUpdate'] > 0) {
            if ($this->ReadAttributeInteger("LastUpdate") == 0) {
                $this->WriteAttributeInteger("LastUpdate", (int)$oldPropValue['LastUpdate']);
            }
        }

        $this->RegisterHook("/hook/smartwithings");

        $interval = $this->ReadPropertyInteger("FetchInterval");
        $this->SetTimerInterval("FetchTimer", $interval * 60 * 1000);

        $this->UpdatePresentations();
        $this->UpdateStatusIcons();
        $this->UpdateConnectionStatus();
    }

    /**
     * Zeigt den aktuellen OAuth-Verbindungsstatus als Klartext-Variable an.
     */
    private function UpdateConnectionStatus(): void {
        if (@IPS_GetObjectIDByIdent("ConnectionStatus", $this->InstanceID) === false) {
            return;
        }

        $clientId = $this->ReadPropertyString("ClientID");
        $accessToken = $this->ReadAttributeString("AccessToken");
        $tokenExpires = $this->ReadAttributeInteger("TokenExpires");

        if ($clientId == "") {
            $this->SetValue("ConnectionStatus", "Nicht konfiguriert");
        } elseif ($accessToken == "") {
            $this->SetValue("ConnectionStatus", "Nicht autorisiert");
        } elseif (time() > $tokenExpires) {
            $this->SetValue("ConnectionStatus", "Token abgelaufen (Refresh bei nächstem Abruf)");
        } else {
            $remaining = $tokenExpires - time();
            $hours = intdiv($remaining, 3600);
            $minutes = intdiv($remaining % 3600, 60);
            $this->SetValue("ConnectionStatus", "Verbunden (Token gültig noch {$hours}h {$minutes}m)");
        }
    }

    /**
     * Setzt Icons und Presentations für die Status-Variablen (ConnectionStatus, LastMeasurement, DeviceBattery, DailyReport).
     */
    private function UpdateStatusIcons(): void {
        $iconMap = [
            'ConnectionStatus' => 'Network',
            'LastMeasurement'  => 'Clock',
            'DeviceBattery'    => 'Battery',
            'DailyReport'      => 'Book',
        ];

        foreach ($iconMap as $ident => $icon) {
            $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varID !== false) {
                IPS_SetIcon($varID, $icon);
            }
        }

        // DailyReport bekommt zusätzlich die VALUE_PRESENTATION für Markdown-Anzeige
        $reportID = @IPS_GetObjectIDByIdent('DailyReport', $this->InstanceID);
        if ($reportID !== false) {
            IPS_SetVariableCustomPresentation($reportID, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
            ]);
        }
    }

    protected function RegisterHook(string $HookPath): bool {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            if (!is_array($hooks)) {
                $hooks = [];
            }
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $HookPath) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return true;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook'=> $HookPath, 'TargetID'=> $this->InstanceID];
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
        return true;
    }

    private function GetRedirectURI(): string {
        $cc_ids = IPS_GetInstanceListByModuleID("{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}");
        if (count($cc_ids) > 0) {
            $url = CC_GetConnectURL($cc_ids[0]);
            if ($url != "") {
                return $url . "/hook/smartwithings";
            }
        }
        // Fallback to local IP if Connect is not active
        return "http://". $_SERVER['HTTP_HOST'] . "/hook/smartwithings";
    }

    public function GetAuthURL(): void {
        $clientId = $this->ReadPropertyString("ClientID");
        if ($clientId == "") {
            echo "Fehler: Client ID ist leer.";
            return;
        }

        $redirectUri = urlencode($this->GetRedirectURI());
        $scope = urlencode("user.metrics,user.info,user.activity");
        $state = md5((string)time());

        $url = "https://account.withings.com/oauth2_user/authorize2?response_type=code&client_id={$clientId}&redirect_uri={$redirectUri}&scope={$scope}&state={$state}";
        
        echo "Bitte öffne diesen Link im Browser, um Symcon mit Withings zu verbinden:\n\n". $url;
    }

    protected function ProcessHookData(): void {
        $this->SendDebug("WebHook", "GET: ". print_r($_GET, true) . " POST: " . print_r($_POST, true), 0);

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
            // Withings checkt den Endpoint
            http_response_code(200);
            return;
        }

        if (isset($_GET['code'])) {
            $code = $_GET['code'];
            $this->SendDebug("WebHook", "Auth-Code erhalten: ". $code, 0);

            $clientId = $this->ReadPropertyString("ClientID");
            $clientSecret = $this->ReadPropertyString("ClientSecret");
            $redirectUri = $this->GetRedirectURI();

            $postData = [
                'action'=> 'requesttoken',
                'grant_type'=> 'authorization_code',
                'client_id'=> $clientId,
                'client_secret'=> $clientSecret,
                'code'=> $code,
                'redirect_uri'=> $redirectUri
            ];

            $this->RequestTokens($postData);
            echo "Erfolgreich autorisiert! Du kannst dieses Fenster nun schließen und in Symcon auf 'Daten jetzt manuell abrufen' klicken.";
            return;
        } 
        
        if (isset($_POST['userid']) || isset($_GET['userid']) || isset($_POST['appli']) || isset($_GET['appli'])) {
            $this->SendDebug("WebHook", "Webhook Notification empfangen, rufe Daten ab...", 0);
            // Direkter Abruf der Daten
            $this->FetchMeasurements();
            return;
        }
        
        echo "Kein gültiger Code oder Webhook empfangen."; 
        return;
    }

    public function SubscribeWebhooks(): void {
        $accessToken = $this->ReadAttributeString("AccessToken");
        if ($accessToken == "") {
            echo "Fehler: Kein Access Token vorhanden.";
            return;
        }

        $callbackUrl = $this->GetRedirectURI();
        $applis = [1, 4, 16]; // 1: weight, 4: activity, 16: heart

        foreach ($applis as $appli) {
            $postData = [
                'action' => 'subscribe',
                'callbackurl' => $callbackUrl,
                'appli' => $appli
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://wbsapi.withings.net/notify");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $accessToken
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            curl_close($ch);

            $this->SendDebug("WebhookSubscribe", "Appli $appli Response: " . $response, 0);
        }
        echo "Webhooks (Gewicht, Aktivität, Herz) wurden abonniert.";
    }

    public function UnsubscribeWebhooks(): void {
        $accessToken = $this->ReadAttributeString("AccessToken");
        if ($accessToken == "") {
            echo "Fehler: Kein Access Token vorhanden.";
            return;
        }

        $callbackUrl = $this->GetRedirectURI();
        $applis = [1, 4, 16];

        foreach ($applis as $appli) {
            $postData = [
                'action' => 'revoke',
                'callbackurl' => $callbackUrl,
                'appli' => $appli
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://wbsapi.withings.net/notify");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $accessToken
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            curl_close($ch);

            $this->SendDebug("WebhookUnsubscribe", "Appli $appli Response: " . $response, 0);
        }
        echo "Webhooks wurden deabonniert.";
    }

    private function RefreshToken(): bool {
        $refreshToken = $this->ReadAttributeString("RefreshToken");
        if ($refreshToken == "") {
            $this->SendDebug("OAuth", "Kein Refresh Token vorhanden. Bitte neu authentifizieren.", 0);
            return false;
        }

        $clientId = $this->ReadPropertyString("ClientID");
        $clientSecret = $this->ReadPropertyString("ClientSecret");

        $postData = [
            'action'=> 'requesttoken',
            'grant_type'=> 'refresh_token',
            'client_id'=> $clientId,
            'client_secret'=> $clientSecret,
            'refresh_token'=> $refreshToken
        ];

        return $this->RequestTokens($postData);
    }

    private function RequestTokens(array $postData): bool {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://wbsapi.withings.net/v2/oauth2");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $httpCode >= 400) {
            $this->SLog('ERROR', 'API-Anfrage fehlgeschlagen', "HTTP $httpCode | Fehler: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        $this->SendDebug("OAuth", "Token Response: ". $response, 0);
        $data = json_decode($response, true);

        if (isset($data['status']) && $data['status'] == 0 && isset($data['body']['access_token'])) {
            $this->WriteAttributeString("AccessToken", $data['body']['access_token']);
            $this->WriteAttributeString("RefreshToken", $data['body']['refresh_token']);
            $this->WriteAttributeInteger("TokenExpires", time() + $data['body']['expires_in'] - 60);
            $this->SendDebug("OAuth", "Tokens erfolgreich gespeichert.", 0);
            $this->UpdateConnectionStatus();
            return true;
        }

        return false;
    }

    protected function Log(string $text): void
    {
        $this->SLog('INFO', $text);
    }

    public function FetchMeasurements(): void {
        $accessToken = $this->ReadAttributeString("AccessToken");
        if ($accessToken == "") {
            $this->SLog('ERROR', 'Kein Access Token vorhanden. Bitte autorisieren.');
            $this->SendDebug("Fetch", "Kein Access Token vorhanden.", 0);
            $this->UpdateConnectionStatus();
            return;
        }

        if (time() > $this->ReadAttributeInteger("TokenExpires")) {
            $this->SendDebug("Fetch", "Token abgelaufen, versuche Refresh...", 0);
            if (!$this->RefreshToken()) {
                $this->SLog('ERROR', 'Token-Refresh fehlgeschlagen!');
                $this->UpdateConnectionStatus();
                return;
            }
            $accessToken = $this->ReadAttributeString("AccessToken");
        }

        $lastUpdate = $this->ReadAttributeInteger("LastUpdate");
        $highestUpdate = $lastUpdate;
        $highestMeasurementDate = 0;
        $offset = 0;
        $pages = 0;
        $newMeasurements = 0;

        do {
            $postData = [
                'action'=> 'getmeas',
                'lastupdate'=> $lastUpdate
            ];
            if ($offset > 0) {
                $postData['offset'] = $offset;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://wbsapi.withings.net/measure");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer ". $accessToken
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($response === false || $httpCode >= 400) {
                $this->SLog('ERROR', 'API-Anfrage fehlgeschlagen', "HTTP $httpCode | Fehler: " . curl_error($ch));
                curl_close($ch);
                break;
            }
            curl_close($ch);

            $data = json_decode($response, true);
            if (isset($data['status']) && $data['status'] == 0) {
                if (isset($data['body']['measuregrps']) && is_array($data['body']['measuregrps'])) {
                    foreach ($data['body']['measuregrps'] as $grp) {
                        if (isset($grp['modified'])) {
                            $highestUpdate = max($highestUpdate, $grp['modified']);
                        }
                        $grpDate = isset($grp['date']) ? $grp['date'] : time();
                        if (isset($grp['date'])) {
                            $highestUpdate = max($highestUpdate, $grp['date']);
                            $highestMeasurementDate = max($highestMeasurementDate, $grp['date']);
                        }
                        if (isset($grp['measures']) && is_array($grp['measures'])) {
                            foreach ($grp['measures'] as $measure) {
                                $this->ProcessMeasurement($measure, $grpDate);
                                $newMeasurements++;
                            }
                        }
                    }
                }
                
                $pages++;
                if (isset($data['body']['more']) && $data['body']['more'] == 1 && isset($data['body']['offset'])) {
                    $offset = $data['body']['offset'];
                } else {
                    $offset = 0; // stop
                }

                // Security stop after 50 pages to prevent endless loop / timeout
                if ($pages > 50) {
                    $offset = 0;
                }

            } else {
                $this->SLog('ERROR', 'Fehler beim Abruf der Messwerte.');
                $this->SendDebug("Fetch", "Fehler beim Abruf: ". $response, 0);
                $offset = 0; // stop on error
            }
        } while ($offset > 0);
        
        if ($highestUpdate > $lastUpdate) {
            $this->WriteAttributeInteger("LastUpdate", $highestUpdate);
        }

        if ($highestMeasurementDate > 0) {
            $currentStr = $this->GetValue("LastMeasurement");
            $currentTs = $currentStr ? strtotime($currentStr) : 0;
            if ($highestMeasurementDate > $currentTs) {
                $this->SetValue("LastMeasurement", date("d.m.Y H:i:s", $highestMeasurementDate));
            }
        }

        // BMI automatisch berechnen wenn Gewicht und Größe vorhanden
        $this->CalculateBMI();

        if ($newMeasurements > 0) {
            if ($this->ReadPropertyBoolean("EnableAI")) {
                $this->EvaluateWithGemini();
            }
        }

        // Gerätestatus im Hintergrund aktualisieren
        $this->FetchDeviceInfo();

        $this->UpdateConnectionStatus();
        $this->SendDebug("Fetch", "Abruf erfolgreich beendet (". $pages . " Seiten, $newMeasurements Messwerte).", 0);
    }

    /**
     * Berechnet den BMI aus den vorhandenen Gewichts- und Größen-Variablen.
     * BMI = Gewicht (kg) / Größe (m)²
     */
    private function CalculateBMI(): void {
        $weightID = @IPS_GetObjectIDByIdent("Measure_" . self::MEASURE_WEIGHT, $this->InstanceID);
        $heightID = @IPS_GetObjectIDByIdent("Measure_" . self::MEASURE_HEIGHT, $this->InstanceID);

        if ($weightID === false || $heightID === false) {
            return;
        }

        $weight = (float)GetValue($weightID);
        $height = (float)GetValue($heightID);

        if ($height <= 0 || $weight <= 0) {
            return;
        }

        $bmi = round($weight / ($height * $height), 1);
        $ident = "Calculated_BMI";

        $this->MaintainVariable($ident, "BMI (Body Mass Index)", 2, "", 10, true);
        $bmiID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($bmiID !== false) {
            IPS_SetVariableCustomPresentation($bmiID, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX' => ' kg/m²',
                'DIGITS' => 1
            ]);
            IPS_SetIcon($bmiID, "Information");

            $archiveIds = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}");
            if (count($archiveIds) > 0) {
                if (!@AC_GetLoggingStatus($archiveIds[0], $bmiID)) {
                    @AC_SetLoggingStatus($archiveIds[0], $bmiID, true);
                    @IPS_ApplyChanges($archiveIds[0]);
                }
            }

            $this->SetValue($ident, $bmi);
        }
    }

    /**
     * Ruft Geräteinformationen (Batterie, Modell, letzter Sync) von der Withings API ab.
     * Endpoint: POST /v2/user action=getdevice
     */
    public function FetchDeviceInfo(): void {
        $accessToken = $this->ReadAttributeString("AccessToken");
        if ($accessToken == "") {
            return;
        }

        if (time() > $this->ReadAttributeInteger("TokenExpires")) {
            if (!$this->RefreshToken()) {
                return;
            }
            $accessToken = $this->ReadAttributeString("AccessToken");
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://wbsapi.withings.net/v2/user");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['action' => 'getdevice']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $accessToken
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $httpCode >= 400) {
            $this->SendDebug("DeviceInfo", "API-Anfrage fehlgeschlagen: HTTP $httpCode", 0);
            curl_close($ch);
            return;
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!isset($data['status']) || $data['status'] != 0 || !isset($data['body']['devices'])) {
            $this->SendDebug("DeviceInfo", "Ungültige Antwort: " . $response, 0);
            return;
        }

        $batteryParts = [];
        foreach ($data['body']['devices'] as $device) {
            $model = $device['model'] ?? 'Unbekannt';
            $battery = $device['battery'] ?? 'unknown';
            $lastSync = isset($device['last_session_date']) 
                ? date("d.m.Y H:i", $device['last_session_date']) 
                : '–';

            // Withings battery levels: "high", "medium", "low"
            $batteryLabel = match($battery) {
                'high'   => 'Hoch',
                'medium' => 'Mittel',
                'low'    => 'Niedrig',
                default  => 'Unbekannt',
            };

            $batteryParts[] = "{$model}: {$batteryLabel} (Sync: {$lastSync})";
        }

        if (count($batteryParts) > 0) {
            $this->SetValue("DeviceBattery", implode(" | ", $batteryParts));
        }

        $this->SendDebug("DeviceInfo", count($data['body']['devices']) . " Gerät(e) gefunden.", 0);
    }

    private function GetMeasurementConfig(int $type): array {
        $name = "Messwert Typ ". $type;
        $suffix = "";
        $icon = "";

        switch ($type) {
            case self::MEASURE_WEIGHT:               $name = "Gewicht"; $suffix = "kg"; $icon = "Scale"; break;
            case self::MEASURE_HEIGHT:               $name = "Größe"; $suffix = "m"; $icon = "Distance"; break;
            case self::MEASURE_FAT_FREE_MASS:        $name = "Fettfreie Masse"; $suffix = "kg"; $icon = "Scale"; break;
            case self::MEASURE_FAT_RATIO:            $name = "Körperfett"; $suffix = "%"; $icon = "Drop"; break;
            case self::MEASURE_FAT_MASS_WEIGHT:      $name = "Fettmasse"; $suffix = "kg"; $icon = "Scale"; break;
            case self::MEASURE_DIASTOLIC_BP:          $name = "Blutdruck (Diastolisch)"; $suffix = "mmHg"; $icon = "Heart"; break;
            case self::MEASURE_SYSTOLIC_BP:           $name = "Blutdruck (Systolisch)"; $suffix = "mmHg"; $icon = "Heart"; break;
            case self::MEASURE_HEART_PULSE:           $name = "Herzfrequenz"; $suffix = "bpm"; $icon = "Heart"; break;
            case self::MEASURE_TEMPERATURE:           $name = "Temperatur"; $suffix = "°C"; $icon = "Temperature"; break;
            case self::MEASURE_SP02:                  $name = "SPO2 (Sauerstoffsättigung)"; $suffix = "%"; $icon = "Heart"; break;
            case self::MEASURE_BODY_TEMPERATURE:      $name = "Körpertemperatur"; $suffix = "°C"; $icon = "Temperature"; break;
            case self::MEASURE_SKIN_TEMPERATURE:      $name = "Hauttemperatur"; $suffix = "°C"; $icon = "Temperature"; break;
            case self::MEASURE_MUSCLE_MASS:           $name = "Muskelmasse"; $suffix = "kg"; $icon = "Scale"; break;
            case self::MEASURE_HYDRATION:             $name = "Wasseranteil"; $suffix = "kg"; $icon = "Drop"; break;
            case self::MEASURE_BONE_MASS:             $name = "Knochenmasse"; $suffix = "kg"; $icon = "Scale"; break;
            case self::MEASURE_PWV:                   $name = "Pulswellengeschwindigkeit"; $suffix = "m/s"; $icon = "Wind"; break;
            case self::MEASURE_VO2_MAX:               $name = "VO2 Max"; $suffix = "ml/min/kg"; $icon = "Heart"; break;
            case self::MEASURE_VISCERAL_FAT:          $name = "Viszeralfett"; $suffix = "%"; $icon = "Drop"; break;
            case self::MEASURE_VASCULAR_AGE:
            case self::MEASURE_VASCULAR_AGE_2:        $name = "Gefäßalter"; $suffix = "Jahre"; $icon = "Clock"; break;
            case self::MEASURE_NERVE_HEALTH:          $name = "Nervenaktivität"; $suffix = "Punkte"; $icon = "Intensity"; break;
            case self::MEASURE_QT_INTERVAL:           $name = "QT-Intervall"; $suffix = "ms"; $icon = "Heart"; break;
            case self::MEASURE_AFIB:                  $name = "Vorhofflimmern"; $suffix = ""; $icon = "Heart"; break;
            case self::MEASURE_EXTRACELLULAR_WATER:   $name = "Extrazelluläres Wasser"; $suffix = "kg"; $icon = "Drop"; break;
            case self::MEASURE_INTRACELLULAR_WATER:   $name = "Intrazelluläres Wasser"; $suffix = "kg"; $icon = "Drop"; break;
            // Body Scan segmented data
            case self::MEASURE_FAT_TORSO:             $name = "Körperfett Rumpf"; $suffix = "%"; $icon = "Drop"; break;
            case self::MEASURE_FAT_ARMS:              $name = "Körperfett Arme"; $suffix = "%"; $icon = "Drop"; break;
            case self::MEASURE_FAT_LEGS:              $name = "Körperfett Beine"; $suffix = "%"; $icon = "Drop"; break;
            case self::MEASURE_FAT_FREE_SEGMENT:      $name = "Fettfreie Masse (Segment)"; $suffix = "kg"; $icon = "Scale"; break;
            case self::MEASURE_FAT_SEGMENT:           $name = "Fettmasse (Segment)"; $suffix = "kg"; $icon = "Scale"; break;
            case self::MEASURE_MUSCLE_SEGMENT:        $name = "Muskelmasse (Segment)"; $suffix = "kg"; $icon = "Scale"; break;
            // Nerve Health (EDA)
            case self::MEASURE_NERVE_SCORE:           $name = "Nervenaktivität Score"; $suffix = "Punkte"; $icon = "Intensity"; break;
            case self::MEASURE_NERVE_LEFT_FOOT:       $name = "Nervenaktivität (Fuß links)"; $suffix = "Punkte"; $icon = "Intensity"; break;
            case self::MEASURE_NERVE_RIGHT_FOOT:      $name = "Nervenaktivität (Fuß rechts)"; $suffix = "Punkte"; $icon = "Intensity"; break;
            // Metabolic
            case self::MEASURE_BMR:                   $name = "Grundumsatz (BMR)"; $suffix = "kcal"; $icon = "Flame"; break;
            case self::MEASURE_METABOLIC_AGE:         $name = "Metabolisches Alter"; $suffix = "Jahre"; $icon = "Clock"; break;
        }

        return [
            'name'=> $name,
            'suffix'=> $suffix,
            'icon'=> $icon
        ];
    }

    private function UpdatePresentations(): void {
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            $ident = $obj['ObjectIdent'];
            if (strpos($ident, "Measure_") === 0) {
                $type = (int)substr($ident, 8);
                $config = $this->GetMeasurementConfig($type);
                
                IPS_SetName($childID, $config['name']);
                
                if ($config['suffix'] != "") {
                    IPS_SetVariableCustomPresentation($childID, [
                        'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                        'SUFFIX' => ' ' . $config['suffix']
                    ]);
                }
                
                if ($config['icon'] != "") {
                    IPS_SetIcon($childID, $config['icon']);
                }
            }
        }
    }

    private function ProcessMeasurement(array $measure, int $timestamp): void {
        if (!isset($measure['type']) || !isset($measure['value'])) {
            return;
        }
        $type = $measure['type'];
        $unit = isset($measure['unit']) ? $measure['unit'] : 0;
        $value = $measure['value'] * pow(10, $unit);

        $ident = "Measure_". $type;
        $config = $this->GetMeasurementConfig($type);

        // Variable dynamisch anlegen falls nicht existent (Cache pro Request-Zyklus)
        if (!isset($this->createdIdents[$ident])) {
            $this->MaintainVariable($ident, $config['name'], 2, "", 0, true);
            $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            
            if ($varID !== false) {
                $archiveIds = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}");
                if (count($archiveIds) > 0) {
                    @AC_SetLoggingStatus($archiveIds[0], $varID, true);
                    @IPS_ApplyChanges($archiveIds[0]);
                }
                
                if ($config['suffix'] != "") {
                    IPS_SetVariableCustomPresentation($varID, [
                        'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                        'SUFFIX' => ' ' . $config['suffix']
                    ]);
                }
                
                if ($config['icon'] != "") {
                    IPS_SetIcon($varID, $config['icon']);
                }
            }
            
            $this->createdIdents[$ident] = true;
        }

        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false) {
            $this->SetValue($ident, $value);
        }
    }

    public function EvaluateWithGemini(): void {
        // SmartGeminiIO auto-discover
        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SLog('ERROR', 'SmartGeminiIO Instanz nicht gefunden! Bitte eine erstellen.');
            return;
        }
        $geminiId = $geminiInstances[0];

        $archiveIDs = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}");
        if (count($archiveIDs) == 0) {
            $this->SLog('ERROR', 'Kein Archive Control gefunden.');
            return;
        }
        $archiveID = $archiveIDs[0];

        $days = $this->ReadPropertyInteger("ArchiveDays");
        $startTime = time() - ($days * 24 * 60 * 60);
        
        // Alle Body Scan 2 relevanten Metriken für den Gemini-Prompt
        $metrics = [
            self::MEASURE_WEIGHT           => "Gewicht (kg)",
            self::MEASURE_FAT_RATIO        => "Körperfett (%)",
            self::MEASURE_MUSCLE_MASS      => "Muskelmasse (kg)",
            self::MEASURE_BONE_MASS        => "Knochenmasse (kg)",
            self::MEASURE_HYDRATION        => "Wasseranteil (kg)",
            self::MEASURE_FAT_MASS_WEIGHT  => "Fettmasse (kg)",
            self::MEASURE_FAT_FREE_MASS    => "Fettfreie Masse (kg)",
            self::MEASURE_VISCERAL_FAT     => "Viszeralfett (%)",
            self::MEASURE_HEART_PULSE      => "Herzfrequenz (bpm)",
            self::MEASURE_DIASTOLIC_BP     => "Blutdruck diastolisch (mmHg)",
            self::MEASURE_SYSTOLIC_BP      => "Blutdruck systolisch (mmHg)",
            self::MEASURE_PWV              => "Pulswellengeschwindigkeit (m/s)",
            self::MEASURE_VASCULAR_AGE     => "Gefäßalter (Jahre)",
            self::MEASURE_NERVE_SCORE      => "Nervengesundheit Score (Punkte)",
            self::MEASURE_BMR              => "Grundumsatz/BMR (kcal)",
            self::MEASURE_METABOLIC_AGE    => "Metabolisches Alter (Jahre)",
            self::MEASURE_EXTRACELLULAR_WATER => "Extrazelluläres Wasser (kg)",
            self::MEASURE_INTRACELLULAR_WATER => "Intrazelluläres Wasser (kg)",
        ];

        $prompt = "Du bist ein motivierender KI-Gesundheits-Coach. Hier sind meine aufgezeichneten Gesundheitsdaten der letzten ". $days . " Tage von meiner Withings Body Scan 2 Waage.\n";
        $prompt .= "Bitte bewerte den Trend der Messwerte, gib mir ein kurzes Feedback und weise auf Besonderheiten hin (z.B. stark steigender Blutdruck, Gewichtsverlust, Veränderungen bei Muskelmasse oder Viszeralfett).\n";
        $prompt .= "Fasse dich kurz, bleibe positiv und präzise. Antworte in Deutsch und formatiere den Text in einfachem Markdown.\n\n";

        // BMI-Wert hinzufügen falls vorhanden
        $bmiID = @IPS_GetObjectIDByIdent("Calculated_BMI", $this->InstanceID);
        if ($bmiID !== false) {
            $bmiValue = GetValue($bmiID);
            if ($bmiValue > 0) {
                $prompt .= "### BMI (berechnet)\n- Aktuell: " . number_format($bmiValue, 1) . " kg/m²\n\n";
            }
        }

        $hasData = false;
        foreach ($metrics as $type => $label) {
            $ident = "Measure_". $type;
            $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varID !== false && AC_GetLoggingStatus($archiveID, $varID)) {
                $values = AC_GetLoggedValues($archiveID, $varID, $startTime, time(), 0);
                if (count($values) > 0) {
                    $prompt .= "### $label\n";
                    $aggregates = AC_GetAggregatedValues($archiveID, $varID, 1, $startTime, time(), 0);
                    $aggregates = array_reverse($aggregates);
                    foreach ($aggregates as $agg) {
                        if ($agg['Duration'] > 0) {
                            $dateStr = date("d.m.", $agg['TimeStamp']);
                            $valStr = number_format($agg['Avg'], 1);
                            $prompt .= "- $dateStr: $valStr\n";
                            $hasData = true;
                        }
                    }
                    $prompt .= "\n";
                }
            }
        }

        if (!$hasData) {
            $this->SLog('WARNING', 'Keine Archivdaten für Gemini Auswertung gefunden.');
            return;
        }

        $instanceId = $this->InstanceID;

        // Async — kein Schema = freier Markdown-Text, Temperatur 0.4
        $script = '<?php
            $result = GIO_Query(' . $geminiId . ',
                ' . var_export($prompt, true) . ',
                \'Du bist ein motivierender KI-Gesundheits-Coach für Withings Body Scan 2 Daten. Antworte auf Deutsch im Markdown-Format. Bewerte Trends, markiere Auffälligkeiten und gib konkrete Tipps.\',
                \'\',
                0.4
            );
            WITHINGS_ProcessGeminiResult(' . $instanceId . ', $result);
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiResult(string $report): void {
        if (empty($report)) {
            $this->SLog('ERROR', 'SmartGeminiIO lieferte keine Antwort.');
            return;
        }

        $this->SetValue('DailyReport', $report);
        $this->SLog('INFO', 'Gemini Gesundheitsbericht erfolgreich generiert.');

        $smtpID = $this->ReadPropertyInteger('SMTPInstanceID');
        if ($smtpID > 0 && IPS_InstanceExists($smtpID)) {
            @SMTP_SendMail($smtpID, 'Dein Gesundheits-Coach Update', $report);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'WithingsDevice: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "1. Withings API Zugangsdaten"
        },
        {
            "type": "Label",
            "caption": "Hier trägst du die Client ID und das Client Secret aus deinem Withings Developer Account ein. Diese Daten brauchst du, damit sich Symcon mit deinem Withings Account verbinden kann."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "ClientID",
                    "caption": "Client ID"
                }
            ]
        },
        {
            "type": "PasswordTextBox",
            "name": "ClientSecret",
            "caption": "Client Secret"
        },
        {
            "type": "Label",
            "caption": "WICHTIG: Die Callback-URL in deinem Withings Developer Account MUSS exakt so lauten:"
        },
        {
            "type": "Label",
            "caption": "https://<DEINE-CONNECT-ID>.ipmagic.de/hook/smartwithings"
        },
        {
            "type": "Label",
            "caption": "2. Einstellungen"
        },
        {
            "type": "Label",
            "caption": "Gib hier an, wie oft deine Daten von Withings abgerufen werden sollen. Wenn du 0 einträgst, wird der automatische Abruf deaktiviert."
        },
        {
            "type": "NumberSpinner",
            "name": "FetchInterval",
            "caption": "Abruf-Intervall (in Min, 0 = deaktiviert, z.B. 240 für Fallback)",
            "minimum": 0,
            "maximum": 1440
        },
        {
            "type": "Label",
            "caption": "3. KI Auswertung (Google Gemini)"
        },
        {
            "type": "Label",
            "caption": "Hier kannst du deinen persönlichen KI-Coach aktivieren. Du brauchst dafür einen API Key von Google. Gib außerdem an, über welchen Zeitraum die Trends berechnet werden sollen und wohin dir der Bericht geschickt werden darf."
        },
        {
            "type": "CheckBox",
            "name": "EnableAI",
            "caption": "Gemini Auswertung nach jedem Abruf aktivieren"
        },
        {
            "type": "Label",
            "caption": "API-Key und Modell werden zentral über die 'Smart Gemini IO' Instanz konfiguriert.\nBitte dort einmalig deinen Google Gemini API-Key hinterlegen."
        },
        {
            "type": "NumberSpinner",
            "name": "ArchiveDays",
            "caption": "Trend-Zeitraum (Tage, 28 = 4 Wochen)",
            "minimum": 1,
            "maximum": 365
        },
        {
            "type": "SelectInstance",
            "name": "SMTPInstanceID",
            "caption": "SMTP Instanz für täglichen Bericht per Mail"
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Mit Withings verbinden (OAuth Login)",
            "onClick": "echo WITHINGS_GetAuthURL($id);"
        },
        {
            "type": "Button",
            "label": "Daten jetzt manuell abrufen",
            "onClick": "WITHINGS_FetchMeasurements($id);"
        },
        {
            "type": "Button",
            "label": "Gerätestatus abrufen",
            "onClick": "WITHINGS_FetchDeviceInfo($id);"
        },
        {
            "type": "Button",
            "label": "Webhooks abonnieren",
            "onClick": "echo WITHINGS_SubscribeWebhooks($id);"
        },
        {
            "type": "Button",
            "label": "Webhooks deabonnieren",
            "onClick": "echo WITHINGS_UnsubscribeWebhooks($id);"
        },
        {
            "type": "Button",
            "label": "KI Auswertung (inkl. Mail) jetzt testen",
            "onClick": "WITHINGS_EvaluateWithGemini($id);"
        }
    ]
}
EOT;
    }
}
