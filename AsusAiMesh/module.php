<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';

class AsusAiMesh extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    use SmartHttp_Trait;

    // ASUS API Constants
    private const ASUS_USER_AGENT = 'asusrouter-Android-DUTUtil-1.0.0.201';
    private const ASUS_TOKEN_BUFFER = 'AsusToken';
    private const ASUS_TOKEN_TIME_BUFFER = 'AsusTokenTime';
    private const ASUS_TOKEN_LIFETIME = 1500; // 25 minutes (tokens expire ~30 min)
    private const ASUS_NODE_MAC_MAP = 'AsusNodeMacMap'; // Buffer: JSON map of node index -> MAC

    public function Create(): void
    {
        parent::Create();

        // DeviceAvailability
        $this->DA_RegisterAvailability(900);

        // Properties
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', 'admin');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyBoolean('UseHTTPS', true);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('MaxNodes', 4);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'ASUSMESH_Update($_IPS[\'TARGET\']);');

        // --- Mesh Overview (1-9) ---
        $this->RegisterVariableInteger('MeshNodesOnline', 'Nodes Online', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Network'
        ], 1);

        $this->RegisterVariableBoolean('FirmwareUpdate', 'Firmware-Update verfügbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Repeat'
        ], 5);

        // --- Control Variables (200-209) with Legacy Profiles ---
        $this->RegisterControlProfiles();

        // LED Control
        $this->RegisterVariableInteger('LED', 'LED', 'ASUSMESH.OnOff', 200);
        $this->EnableAction('LED');

        // WiFi Band Controls
        $this->RegisterVariableInteger('WiFi_2G', 'WiFi 2.4 GHz', 'ASUSMESH.OnOff', 201);
        $this->EnableAction('WiFi_2G');

        $this->RegisterVariableInteger('WiFi_5G1', 'WiFi 5 GHz (Band 1)', 'ASUSMESH.OnOff', 202);
        $this->EnableAction('WiFi_5G1');

        $this->RegisterVariableInteger('WiFi_5G2', 'WiFi 5 GHz (Band 2/Backhaul)', 'ASUSMESH.OnOff', 203);
        $this->EnableAction('WiFi_5G2');

        $this->RegisterVariableInteger('WiFi_6G', 'WiFi 6 GHz', 'ASUSMESH.OnOff', 204);
        $this->EnableAction('WiFi_6G');

        // Guest WiFi
        $this->RegisterVariableInteger('GuestWiFi', 'Gästenetzwerk (Party)', 'ASUSMESH.OnOff', 205);
        $this->EnableAction('GuestWiFi');

        // Reboot
        $this->RegisterVariableInteger('Reboot', 'Router neustarten', 'ASUSMESH.Reboot', 206);
        $this->EnableAction('Reboot');

        // --- Diagnostik (900+) ---
        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Clock'
        ], 999);
    }

    /**
     * Helper to set a variable value within a Dummy Module.
     */
    private function SetNodeValue(int $nodeNum, string $ident, mixed $value): void
    {
        $dummyID = @IPS_GetObjectIDByIdent("Node{$nodeNum}", $this->InstanceID);
        if ($dummyID !== false) {
            $vid = @IPS_GetObjectIDByIdent($ident, $dummyID);
            if ($vid !== false) {
                SetValue($vid, $value);
            }
        }
    }

    /**
     * Helper to get a variable value from a Dummy Module.
     */
    private function GetNodeValue(int $nodeNum, string $ident): mixed
    {
        $dummyID = @IPS_GetObjectIDByIdent("Node{$nodeNum}", $this->InstanceID);
        if ($dummyID !== false) {
            $vid = @IPS_GetObjectIDByIdent($ident, $dummyID);
            if ($vid !== false) {
                return GetValue($vid);
            }
        }
        return null;
    }

    /**
     * Helper to maintain a variable under a Dummy Module.
     */
    private function MaintainNodeVariable(int $parentID, string $Ident, string $Name, int $Type, int $Position, string $Icon = '', string $Suffix = ''): void
    {
        $vid = @IPS_GetObjectIDByIdent($Ident, $parentID);
        if ($vid === false) {
            $vid = IPS_CreateVariable($Type);
            IPS_SetParent($vid, $parentID);
            IPS_SetIdent($vid, $Ident);
            IPS_SetName($vid, $Name);
            IPS_SetPosition($vid, $Position);
        }
        
        // Enforce presentation settings (read-only value presentation)
        IPS_SetVariableCustomPresentation($vid, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => $Icon,
            'SUFFIX'       => $Suffix
        ]);
    }

    /**
     * Registers node-specific Dummy Modules and their variables.
     */
    private function RegisterNodeVariables(): void
    {
        $maxNodes = $this->ReadPropertyInteger('MaxNodes');
        for ($n = 1; $n <= $maxNodes; $n++) {
            // Get or create Dummy Module
            $dummyID = @IPS_GetObjectIDByIdent("Node{$n}", $this->InstanceID);
            if ($dummyID === false) {
                // '{485D0419-BE97-4548-AA9C-C083EB82E61E}' is the GUID for Dummy Module
                $dummyID = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
                IPS_SetParent($dummyID, $this->InstanceID);
                IPS_SetIdent($dummyID, "Node{$n}");
                IPS_SetName($dummyID, "Node {$n}");
                IPS_SetPosition($dummyID, 10 + $n);
            }

            // Node Status (0=Boolean, 1=Integer, 2=Float, 3=String)
            $this->MaintainNodeVariable($dummyID, 'Online', 'Status', 0, 1, 'Network');
            $this->MaintainNodeVariable($dummyID, 'Name', 'Name', 3, 2, 'Information');
            $this->MaintainNodeVariable($dummyID, 'IP', 'IP-Adresse', 3, 3, 'Distance');
            $this->MaintainNodeVariable($dummyID, 'Firmware', 'Firmware', 3, 4, 'Gear');
            $this->MaintainNodeVariable($dummyID, 'Uptime', 'Uptime', 3, 6, 'Clock');

            // System Monitoring
            $this->MaintainNodeVariable($dummyID, 'CPU', 'CPU', 2, 10, 'Gauge', ' %');
            $this->MaintainNodeVariable($dummyID, 'RAM', 'RAM', 2, 11, 'Gauge', ' %');
            $this->MaintainNodeVariable($dummyID, 'TempCPU', 'CPU Temperatur', 2, 12, 'Temperature', ' °C');
        }
    }

    /**
     * Creates legacy variable profiles for actionable controls.
     */
    private function RegisterControlProfiles(): void
    {
        // On/Off Profile
        if (!IPS_VariableProfileExists('ASUSMESH.OnOff')) {
            IPS_CreateVariableProfile('ASUSMESH.OnOff', 1); // Integer
            IPS_SetVariableProfileIcon('ASUSMESH.OnOff', 'Power');
            IPS_SetVariableProfileAssociation('ASUSMESH.OnOff', 0, 'Aus', 'Cross', 0xFF4444);
            IPS_SetVariableProfileAssociation('ASUSMESH.OnOff', 1, 'An', 'Ok', 0x00CC44);
        }

        // Reboot Profile
        if (!IPS_VariableProfileExists('ASUSMESH.Reboot')) {
            IPS_CreateVariableProfile('ASUSMESH.Reboot', 1); // Integer
            IPS_SetVariableProfileIcon('ASUSMESH.Reboot', 'Power');
            IPS_SetVariableProfileAssociation('ASUSMESH.Reboot', 0, 'Bereit', 'Ok', 0x00CC44);
            IPS_SetVariableProfileAssociation('ASUSMESH.Reboot', 1, 'Neustarten!', 'Warning', 0xFF4444);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Validate config
        if (empty($this->ReadPropertyString('Host')) || empty($this->ReadPropertyString('Password'))) {
            $this->SetStatus(104); // IS_INACTIVE
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $this->SetStatus(102); // IS_ACTIVE

        // DeviceAvailability Presentation
        $this->DA_ApplyPresentation();

        // Setup Dummy Modules and Node Variables
        $this->RegisterNodeVariables();

        // Node Online/Offline Presentations
        $maxNodes = $this->ReadPropertyInteger('MaxNodes');
        for ($n = 1; $n <= $maxNodes; $n++) {
            $this->ApplyNodeOnlinePresentation($n);
        }

        // Firmware Update Presentation
        $fwOptions = json_encode([
            ['Value' => false, 'Caption' => 'Aktuell', 'IconValue' => 'Ok', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00CC44, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC44],
            ['Value' => true, 'Caption' => 'Update verfügbar', 'IconValue' => 'Repeat', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFF8800, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF8800]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('FirmwareUpdate'), [
            'PRESENTATION'  => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON'          => 'Repeat',
            'COLOR'         => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE'  => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW'  => true,
            'OPTIONS'       => $fwOptions
        ]);

        // Start timer
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

        // Initial fetch
        $this->Update();
    }

    /**
     * Sets custom presentation for Node{N}_Online variable.
     */
    private function ApplyNodeOnlinePresentation(int $n): void
    {
        $dummyID = @IPS_GetObjectIDByIdent("Node{$n}", $this->InstanceID);
        if ($dummyID === false) {
            return;
        }

        $varID = @IPS_GetObjectIDByIdent('Online', $dummyID);
        if ($varID === false || $varID === 0) {
            return;
        }

        $options = json_encode([
            ['Value' => false, 'Caption' => 'Offline', 'IconValue' => 'NetworkDisconnected', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFF4444, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF4444],
            ['Value' => true, 'Caption' => 'Online', 'IconValue' => 'Network', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00CC44, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC44]
        ]);

        IPS_SetVariableCustomPresentation($varID, [
            'PRESENTATION'  => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON'          => 'Network',
            'COLOR'         => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE'  => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW'  => true,
            'OPTIONS'       => $options
        ]);
    }

    // =========================================================================
    // RequestAction
    // =========================================================================

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                break;

            case 'LED':
                $this->SetLED((int)$Value);
                break;

            case 'WiFi_2G':
                $this->SetWiFiBand('wl0_radio', (int)$Value);
                break;

            case 'WiFi_5G1':
                $this->SetWiFiBand('wl1_radio', (int)$Value);
                break;

            case 'WiFi_5G2':
                $this->SetWiFiBand('wl2_radio', (int)$Value);
                break;

            case 'WiFi_6G':
                $this->SetWiFiBand('wl3_radio', (int)$Value);
                break;

            case 'GuestWiFi':
                $this->SetGuestWiFi((int)$Value);
                break;

            case 'Reboot':
                if ((int)$Value === 1) {
                    $this->RebootRouter();
                }
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    // =========================================================================
    // Public API Methods (Timer + Buttons)
    // =========================================================================

    /**
     * Main update method - called by timer and "Update Now" button.
     */
    public function Update(): void
    {
        $token = $this->AsusGetToken();
        if ($token === null) {
            $this->DA_SetAvailable(false, 'Login fehlgeschlagen');
            $this->SetStatus(201);
            return;
        }

        // Combined hook request for maximum performance
        $hooks = implode(';', [
            'get_cfg_clientlist()',
            'get_clientlist()',
            'get_allclientlist()',
            'cpu_usage(appobj)',
            'memory_usage(appobj)',
            'nvram_get(led_val)',
            'nvram_get(wl0_radio)',
            'nvram_get(wl1_radio)',
            'nvram_get(wl2_radio)',
            'nvram_get(wl3_radio)',
            'nvram_get(wl0.1_bss_enabled)',
            'nvram_get(wl1.1_bss_enabled)',
            'nvram_get(webs_state_flag)',
            'uptime()',
        ]);

        $data = $this->AsusGet($hooks, $token);
        if ($data === null) {
            // Token may have expired, try once more
            $this->AsusInvalidateToken();
            $token = $this->AsusGetToken();
            if ($token === null) {
                $this->DA_SetAvailable(false, 'Login fehlgeschlagen');
                $this->SetStatus(201);
                return;
            }
            $data = $this->AsusGet($hooks, $token);
            if ($data === null) {
                $this->DA_SetAvailable(false, 'API nicht erreichbar');
                $this->SetStatus(200);
                return;
            }
        }

        $this->DA_SetAvailable(true);
        $this->SetStatus(102);

        // Parse mesh nodes
        $this->ParseMeshNodes($data);

        // Parse system stats (CPU, RAM)
        $this->ParseSystemStats($data);

        // Parse control states
        $this->ParseControlStates($data);

        // Firmware update check
        $fwFlag = $data['webs_state_flag'] ?? '0';
        $this->SetValue('FirmwareUpdate', $fwFlag !== '' && $fwFlag !== '0');

        // Temperature (separate endpoint)
        $this->FetchTemperatures($token);

        // Update timestamp
        $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));
    }

    /**
     * Test connection button handler.
     */
    public function TestConnection(): string
    {
        $this->AsusInvalidateToken();
        $token = $this->AsusGetToken();
        if ($token === null) {
            return 'Verbindung fehlgeschlagen! Bitte Host, Benutzername und Passwort prüfen.';
        }

        // Try a simple hook to verify
        $data = $this->AsusGet('nvram_get(productid)', $token);
        if ($data === null) {
            return 'Login erfolgreich, aber API-Aufruf fehlgeschlagen.';
        }

        $productId = $data['productid'] ?? 'Unbekannt';
        return "Verbindung erfolgreich! Router: {$productId}";
    }

    /**
     * Dumps the raw API response for debugging purposes.
     */
    public function DumpDebug(): string
    {
        $token = $this->AsusGetToken();
        if ($token === null) {
            return "Fehler: Kein Token erhalten.";
        }

        $hooks = implode(';', [
            'get_cfg_clientlist()',
            'netdev(all)',
            'cpu_usage(appobj)',
            'memory_usage(appobj)',
            'nvram_get(led_val)',
            'nvram_get(wl0_radio)',
            'nvram_get(wl1_radio)',
            'nvram_get(wl2_radio)',
            'nvram_get(wl3_radio)',
            'nvram_get(wl0.1_bss_enabled)',
            'nvram_get(wl1.1_bss_enabled)',
            'nvram_get(webs_state_flag)',
            'uptime()',
        ]); $data = $this->AsusGet($hooks, $token);
        
        $host = $this->ReadPropertyString('Host');
        $useSSL = $this->ReadPropertyBoolean('UseHTTPS');
        $protocol = $useSSL ? 'https' : 'http';
        
        $ch = curl_init("{$protocol}://{$host}/ajax_coretmp.asp");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, self::ASUS_USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIE, "asus_token={$token}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $tempData = curl_exec($ch);
        curl_close($ch);

        return "APP_GET:\n" . print_r($data, true) . "\n\nTEMP_DATA:\n" . (string)$tempData;
    }

    // =========================================================================
    // ASUS API Layer
    // =========================================================================

    /**
     * Gets a cached token or performs login.
     */
    private function AsusGetToken(): ?string
    {
        // Check cached token
        $token = $this->GetBuffer(self::ASUS_TOKEN_BUFFER);
        $tokenTime = (int)$this->GetBuffer(self::ASUS_TOKEN_TIME_BUFFER);

        if (!empty($token) && (time() - $tokenTime) < self::ASUS_TOKEN_LIFETIME) {
            return $token;
        }

        // Need to login
        return $this->AsusLogin();
    }

    /**
     * Performs login and caches the token.
     */
    private function AsusLogin(): ?string
    {
        $host = $this->ReadPropertyString('Host');
        $user = $this->ReadPropertyString('Username');
        $pass = $this->ReadPropertyString('Password');
        $useSSL = $this->ReadPropertyBoolean('UseHTTPS');

        $protocol = $useSSL ? 'https' : 'http';
        $url = "{$protocol}://{$host}/login.cgi";

        $auth = base64_encode("{$user}:{$pass}");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "login_authorization={$auth}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, self::ASUS_USER_AGENT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('AsusLogin', "HTTP {$httpCode} | Error: {$error}", 0);
            return null;
        }

        $json = json_decode((string)$response, true);
        if (!is_array($json) || empty($json['asus_token'])) {
            $this->SendDebug('AsusLogin', 'No asus_token in response: ' . (string)$response, 0);
            return null;
        }

        $token = $json['asus_token'];
        $this->SetBuffer(self::ASUS_TOKEN_BUFFER, $token);
        $this->SetBuffer(self::ASUS_TOKEN_TIME_BUFFER, (string)time());

        $this->SendDebug('AsusLogin', 'Login erfolgreich, Token erhalten', 0);
        return $token;
    }

    /**
     * Invalidates the cached token.
     */
    private function AsusInvalidateToken(): void
    {
        $this->SetBuffer(self::ASUS_TOKEN_BUFFER, '');
        $this->SetBuffer(self::ASUS_TOKEN_TIME_BUFFER, '0');
    }

    /**
     * Fetches data from /appGet.cgi with combined hooks.
     */
    private function AsusGet(string $hooks, string $token): ?array
    {
        $host = $this->ReadPropertyString('Host');
        $useSSL = $this->ReadPropertyBoolean('UseHTTPS');
        $protocol = $useSSL ? 'https' : 'http';
        $url = "{$protocol}://{$host}/appGet.cgi";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "hook={$hooks}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, self::ASUS_USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIE, "asus_token={$token}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('AsusGet', "HTTP {$httpCode} | Error: {$error} | Hooks: {$hooks}", 0);
            return null;
        }

        // ASUS sometimes returns non-standard JSON, try to clean it
        $response = trim((string)$response);
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SendDebug('AsusGet', 'JSON Parse Error: ' . json_last_error_msg() . ' | Response: ' . substr($response, 0, 500), 0);
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Fetches temperature data from /ajax_coretmp.asp.
     */
    private function FetchTemperatures(string $token): void
    {
        $host = $this->ReadPropertyString('Host');
        $useSSL = $this->ReadPropertyBoolean('UseHTTPS');
        $protocol = $useSSL ? 'https' : 'http';
        $url = "{$protocol}://{$host}/ajax_coretmp.asp";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, self::ASUS_USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIE, "asus_token={$token}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('FetchTemperatures', "HTTP {$httpCode} - Temperaturen nicht verfügbar", 0);
            return;
        }

        // Response format varies, typically: "1 = cpu_temp\n2 = 2g_temp\n3 = 5g_temp\n..."
        // Or JSON-like: {"cpu_temperature":"55","2.4 GHz":"48","5 GHz":"52"}
        $response = trim((string)$response);
        $this->SendDebug('Temperature', $response, 0);

        // Try to parse as key=value pairs
        $temps = $this->ParseTemperatureResponse($response);

        // Apply to Node 1 (controller) - for AiMesh nodes we'd need per-node queries
        if (!empty($temps)) {
            if (isset($temps['cpu'])) {
                $this->SetNodeValue(1, 'TempCPU', (float)$temps['cpu']);
            }
            if (isset($temps['2g'])) {
                $this->SetNodeValue(1, 'Temp2G', (float)$temps['2g']);
            }
            if (isset($temps['5g'])) {
                $this->SetNodeValue(1, 'Temp5G', (float)$temps['5g']);
            }
            if (isset($temps['6g'])) {
                $this->SetNodeValue(1, 'Temp6G', (float)$temps['6g']);
            }
        }
    }

    /**
     * Parses the temperature response from ajax_coretmp.asp.
     * Handles various response formats used by different firmware versions.
     */
    private function ParseTemperatureResponse(string $response): array
    {
        $temps = [];

        // Try JSON format first
        $json = json_decode($response, true);
        if (is_array($json)) {
            foreach ($json as $key => $value) {
                $key = strtolower((string)$key);
                $val = (float)preg_replace('/[^0-9.]/', '', (string)$value);
                if ($val <= 0) continue;
                if (str_contains($key, 'cpu')) $temps['cpu'] = $val;
                elseif (str_contains($key, '2.4') || str_contains($key, '2g')) $temps['2g'] = $val;
                elseif (str_contains($key, '5g') || str_contains($key, '5 g')) {
                    if (!isset($temps['5g'])) $temps['5g'] = $val;
                }
                elseif (str_contains($key, '6g') || str_contains($key, '6 g')) $temps['6g'] = $val;
            }
            return $temps;
        }

        // Try query string format: curr_coreTmp_cpu=66.961&curr_coreTmp_wl0=39&curr_coreTmp_wl1=42...
        if (str_contains($response, '=')) {
            parse_str(str_replace("\n", "&", $response), $parsed);
            foreach ($parsed as $key => $value) {
                $key = strtolower((string)$key);
                $val = (float)$value;
                if ($val <= 0) continue;
                if (str_contains($key, 'cpu')) $temps['cpu'] = $val;
                elseif (str_contains($key, 'wl0') || str_contains($key, '2g')) $temps['2g'] = $val;
                elseif (str_contains($key, 'wl1') || str_contains($key, '5g')) $temps['5g'] = $val;
                elseif (str_contains($key, 'wl2') || str_contains($key, '6g')) $temps['6g'] = $val;
            }
            if (!empty($temps)) return $temps;
        }

        // Fallback for simple line-based format like "cpu_temperature:55"
        if (preg_match('/cpu.*?(\d+(?:\.\d+)?)/i', $response, $m) && (float)$m[1] > 0) $temps['cpu'] = (float)$m[1];
        if (preg_match('/(?:2\.4|2g|wl0).*?(\d+(?:\.\d+)?)/i', $response, $m) && (float)$m[1] > 0) $temps['2g'] = (float)$m[1];
        if (preg_match('/(?:5\s?g|wl1).*?(\d+(?:\.\d+)?)/i', $response, $m) && (float)$m[1] > 0) $temps['5g'] = (float)$m[1];
        if (preg_match('/(?:6\s?g|wl2).*?(\d+(?:\.\d+)?)/i', $response, $m) && (float)$m[1] > 0) $temps['6g'] = (float)$m[1];

        return $temps;
    }

    /**
     * Sends a control command via /apply.cgi.
     */
    private function AsusApply(array $nvram, string $actionScript = ''): bool
    {
        $token = $this->AsusGetToken();
        if ($token === null) {
            $this->SendDebug('AsusApply', 'Kein Token verfügbar', 0);
            return false;
        }

        $host = $this->ReadPropertyString('Host');
        $useSSL = $this->ReadPropertyBoolean('UseHTTPS');
        $protocol = $useSSL ? 'https' : 'http';
        $url = "{$protocol}://{$host}/apply.cgi";

        $payload = array_merge([
            'action_mode' => 'apply',
            'action_script' => $actionScript,
            'current_page' => 'Main_Login.asp',
        ], $nvram);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, self::ASUS_USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIE, "asus_token={$token}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('AsusApply', "HTTP {$httpCode} | Error: {$error}", 0);
            return false;
        }

        $this->SendDebug('AsusApply', "OK: " . json_encode($payload), 0);
        return true;
    }

    // =========================================================================
    // Data Parsing
    // =========================================================================

    /**
     * Parses AiMesh node data from get_cfg_clientlist.
     */
    private function ParseMeshNodes(array $data): void
    {
        $nodeList = $data['get_cfg_clientlist'] ?? [];
        if (!is_array($nodeList)) {
            $this->SendDebug('ParseMeshNodes', 'Keine Node-Liste erhalten', 0);
            return;
        }

        $maxNodes = $this->ReadPropertyInteger('MaxNodes');
        $onlineCount = 0;
        $macMap = [];

        for ($i = 0; $i < min(count($nodeList), $maxNodes); $i++) {
            $node = $nodeList[$i];
            $n = $i + 1;

            $isOnline = ($node['online'] ?? '0') === '1';
            $alias = $node['alias'] ?? ($node['ui_model_name'] ?? ($node['model_name'] ?? "Node {$n}"));
            $ip = $node['ip'] ?? '';
            $fwVer = $node['fwver'] ?? '';
            $mac = $node['mac'] ?? '';

            if ($isOnline) {
                $onlineCount++;
            }

            $macMap[$n] = $mac;

            $this->SetNodeValue($n, 'Online', $isOnline);
            $this->SetNodeValue($n, 'Name', (string)$alias);
            $this->SetNodeValue($n, 'IP', (string)$ip);
            $this->SetNodeValue($n, 'Firmware', (string)$fwVer);
        }

        // Clear unused nodes
        for ($n = count($nodeList) + 1; $n <= $maxNodes; $n++) {
            $this->SetNodeValue($n, 'Online', false);
            $this->SetNodeValue($n, 'Name', 'Nicht konfiguriert');
            $this->SetNodeValue($n, 'IP', '');
            $this->SetNodeValue($n, 'Firmware', '');
        }

        $this->SetValue('MeshNodesOnline', $onlineCount);

        // Save MAC map for client assignment
        $this->SetBuffer(self::ASUS_NODE_MAC_MAP, json_encode($macMap));
    }

    /**
     * Parses CPU and RAM usage.
     */
    private function ParseSystemStats(array $data): void
    {
        // CPU usage - format varies: {"cpu1_usage":"12","cpu2_usage":"8",...,"cpu_total":"10"}
        // or {"cpu_usage":{"cpu1_total":"1234","cpu1_usage":"12",...}}
        if (isset($data['cpu_usage'])) {
            $cpuData = $data['cpu_usage'];
            if (is_array($cpuData)) {
                // Try to get total CPU usage
                $totalUsage = 0;
                $coreCount = 0;
                foreach ($cpuData as $key => $value) {
                    if (preg_match('/^cpu(\d+)_usage$/', (string)$key)) {
                        $totalUsage += (float)$value;
                        $coreCount++;
                    }
                }
                if ($coreCount > 0) {
                    $this->SetNodeValue(1, 'CPU', round($totalUsage / $coreCount, 1));
                } elseif (isset($cpuData['cpu_total'])) {
                    $this->SetNodeValue(1, 'CPU', (float)$cpuData['cpu_total']);
                }
            }
        }

        // Memory usage - format: {"mem_total":"xxx","mem_free":"yyy","mem_used":"zzz"}
        if (isset($data['memory_usage'])) {
            $memData = $data['memory_usage'];
            if (is_array($memData)) {
                $total = (float)($memData['mem_total'] ?? 0);
                $used = (float)($memData['mem_used'] ?? 0);
                if ($total > 0) {
                    $this->SetNodeValue(1, 'RAM', round(($used / $total) * 100, 1));
                }
            }
        }

        // Uptime
        if (isset($data['uptime'])) {
            $uptime = (string)$data['uptime'];
            // Format the uptime string
            if (preg_match('/(\d+)\s*days?,?\s*(\d+):(\d+):(\d+)/', $uptime, $m)) {
                $formatted = "{$m[1]}d {$m[2]}h {$m[3]}m";
                $this->SetNodeValue(1, 'Uptime', $formatted);
            } elseif (is_numeric($uptime) && (int)$uptime > 0) {
                $u = (int)$uptime;
                $d = floor($u / 86400);
                $h = floor(($u % 86400) / 3600);
                $m = floor(($u % 3600) / 60);
                $this->SetNodeValue(1, 'Uptime', "{$d}d {$h}h {$m}m");
            } else {
                $this->SetNodeValue(1, 'Uptime', trim($uptime));
            }
        }
    }

    /**
     * Parses current states of controllable features.
     */
    private function ParseControlStates(array $data): void
    {
        // 1. Try to read from top-level nvram_get keys
        $ledVal = isset($data['led_val']) ? (string)$data['led_val'] : null;
        $wl0 = isset($data['wl0_radio']) ? (string)$data['wl0_radio'] : null;
        $wl1 = isset($data['wl1_radio']) ? (string)$data['wl1_radio'] : null;
        $wl2 = isset($data['wl2_radio']) ? (string)$data['wl2_radio'] : null;
        $wl3 = isset($data['wl3_radio']) ? (string)$data['wl3_radio'] : null;
        $guest24 = isset($data['wl0.1_bss_enabled']) ? (string)$data['wl0.1_bss_enabled'] : null;
        $guest5 = isset($data['wl1.1_bss_enabled']) ? (string)$data['wl1.1_bss_enabled'] : null;

        // 2. Fallback to get_cfg_clientlist data if top-level is missing (AP Mode)
        $nodes = $data['get_cfg_clientlist'] ?? [];
        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if (!isset($node['config']) || !is_array($node['config'])) continue;
                $cfg = $node['config'];
                
                // LED Fallback (take from first node that has it)
                if ($ledVal === null && isset($cfg['ctrl_led']['led_val'])) {
                    $ledVal = (string)$cfg['ctrl_led']['led_val'];
                }
                
                // WiFi Radios Fallback
                if (isset($cfg['wireless']) && is_array($cfg['wireless'])) {
                    $wl = $cfg['wireless'];
                    if ($wl0 === null && isset($wl['wl0_radio'])) $wl0 = (string)$wl['wl0_radio'];
                    if ($wl1 === null && isset($wl['wl1_radio'])) $wl1 = (string)$wl['wl1_radio'];
                    if ($wl2 === null && isset($wl['wl2_radio'])) $wl2 = (string)$wl['wl2_radio'];
                    if ($wl3 === null && isset($wl['wl3_radio'])) $wl3 = (string)$wl['wl3_radio'];
                }
            }
        }

        // Update Variables
        if ($ledVal !== null) $this->SetValue('LED', (int)$ledVal);
        if ($wl0 !== null) $this->SetValue('WiFi_2G', (int)$wl0);
        if ($wl1 !== null) $this->SetValue('WiFi_5G1', (int)$wl1);
        if ($wl2 !== null) $this->SetValue('WiFi_5G2', (int)$wl2);
        if ($wl3 !== null) $this->SetValue('WiFi_6G', (int)$wl3);

        // Guest WiFi
        // In AP mode, nvram_get might fail. We need the exact field from get_cfg_clientlist.
        if ($guest24 !== null || $guest5 !== null) {
            $g24 = (int)($guest24 ?? 0);
            $g5 = (int)($guest5 ?? 0);
            $this->SetValue('GuestWiFi', ($g24 > 0 || $g5 > 0) ? 1 : 0);
        }
    }

    // =========================================================================
    // Control Actions
    // =========================================================================

    /**
     * Toggles LED on/off.
     */
    private function SetLED(int $value): void
    {
        $success = $this->AsusApply([
            'led_val' => (string)$value,
        ], 'restart_leds');

        if ($success) {
            $this->SetValue('LED', $value);
        }
    }

    /**
     * Toggles a WiFi band radio on/off.
     */
    private function SetWiFiBand(string $nvramKey, int $value): void
    {
        $success = $this->AsusApply([
            $nvramKey => (string)$value,
        ], 'restart_wireless');

        if ($success) {
            // Map nvram key to variable ident
            $identMap = [
                'wl0_radio' => 'WiFi_2G',
                'wl1_radio' => 'WiFi_5G1',
                'wl2_radio' => 'WiFi_5G2',
                'wl3_radio' => 'WiFi_6G',
            ];
            $ident = $identMap[$nvramKey] ?? '';
            if (!empty($ident)) {
                $this->SetValue($ident, $value);
            }
        }
    }

    /**
     * Toggles guest WiFi (VillaKunterbunt_Party) on/off.
     */
    private function SetGuestWiFi(int $value): void
    {
        // Enable/disable guest network on 2.4 GHz and 5 GHz
        $success = $this->AsusApply([
            'wl0.1_bss_enabled' => (string)$value,
            'wl1.1_bss_enabled' => (string)$value,
        ], 'restart_wireless');

        if ($success) {
            $this->SetValue('GuestWiFi', $value);
        }
    }

    /**
     * Reboots the router.
     */
    private function RebootRouter(): void
    {
        $this->SendDebug('Reboot', 'Router-Neustart wird ausgelöst...', 0);

        $success = $this->AsusApply([
            'rc_service' => 'reboot',
        ]);

        if ($success) {
            $this->SetValue('Reboot', 1);
            // Reset reboot button after 5 seconds
            IPS_Sleep(5000);
            $this->SetValue('Reboot', 0);
            // Invalidate token since router is rebooting
            $this->AsusInvalidateToken();
        }
    }
    // =========================================================================
    // Configuration Form
    // =========================================================================

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "🌐 Verbindung zum AiMesh Controller",
            "expanded": true,
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "ValidationTextBox",
                            "name": "Host",
                            "caption": "Host / IP-Adresse",
                            "width": "300px"
                        },
                        {
                            "type": "CheckBox",
                            "name": "UseHTTPS",
                            "caption": "HTTPS verwenden"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "ValidationTextBox",
                            "name": "Username",
                            "caption": "Benutzername",
                            "width": "200px"
                        },
                        {
                            "type": "PasswordTextBox",
                            "name": "Password",
                            "caption": "Passwort",
                            "width": "300px"
                        }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "⚙️ Einstellungen",
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "UpdateInterval",
                    "caption": "Aktualisierungsintervall (Sekunden)",
                    "minimum": 30,
                    "maximum": 3600,
                    "suffix": " Sek."
                },
                {
                    "type": "NumberSpinner",
                    "name": "MaxNodes",
                    "caption": "Anzahl Mesh-Nodes",
                    "minimum": 1,
                    "maximum": 8
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "Button",
                    "caption": "🔄 Jetzt aktualisieren",
                    "onClick": "ASUSMESH_Update($id);"
                },
                {
                    "type": "Button",
                    "caption": "🔑 Verbindung testen",
                    "onClick": "echo ASUSMESH_TestConnection($id);",
                    "icon": "Key"
                }
            ]
        }
    ],
    "status": [
        {"code": 102, "icon": "active",   "caption": "Aktiv"},
        {"code": 104, "icon": "inactive", "caption": "Nicht konfiguriert – Bitte Host und Passwort eingeben"},
        {"code": 200, "icon": "error",    "caption": "Verbindungsfehler – Router nicht erreichbar"},
        {"code": 201, "icon": "error",    "caption": "Login fehlgeschlagen – Benutzername oder Passwort falsch"}
    ]
}
EOT;
    }

    // =========================================================================
    // Logging
    // =========================================================================

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'AsusAiMesh: ' . $Message);
        return true;
    }
}
