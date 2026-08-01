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

        $this->RegisterVariableInteger('TotalClients', 'Verbundene Geräte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'People'
        ], 2);

        $this->RegisterVariableString('ClientList', 'Client-Übersicht', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Database'
        ], 3);

        $this->RegisterVariableBoolean('FirmwareUpdate', 'Firmware-Update verfügbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Repeat'
        ], 5);

        // --- Node Variables (dynamically based on MaxNodes) ---
        // These are registered for the configured MaxNodes count
        $this->RegisterNodeVariables();

        // --- Control Variables (200-209) with Legacy Profiles ---
        $this->RegisterControlProfiles();

        // LED Control
        $this->RegisterVariableInteger('LED', 'LED', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'ASUSMESH.OnOff'
        ], 200);
        $this->EnableAction('LED');

        // WiFi Band Controls
        $this->RegisterVariableInteger('WiFi_2G', 'WiFi 2.4 GHz', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'ASUSMESH.OnOff'
        ], 201);
        $this->EnableAction('WiFi_2G');

        $this->RegisterVariableInteger('WiFi_5G1', 'WiFi 5 GHz (Band 1)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'ASUSMESH.OnOff'
        ], 202);
        $this->EnableAction('WiFi_5G1');

        $this->RegisterVariableInteger('WiFi_5G2', 'WiFi 5 GHz (Band 2/Backhaul)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'ASUSMESH.OnOff'
        ], 203);
        $this->EnableAction('WiFi_5G2');

        $this->RegisterVariableInteger('WiFi_6G', 'WiFi 6 GHz', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'ASUSMESH.OnOff'
        ], 204);
        $this->EnableAction('WiFi_6G');

        // Guest WiFi
        $this->RegisterVariableInteger('GuestWiFi', 'Gästenetzwerk (Party)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'ASUSMESH.OnOff'
        ], 205);
        $this->EnableAction('GuestWiFi');

        // Reboot
        $this->RegisterVariableInteger('Reboot', 'Router neustarten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'PROFILE'      => 'ASUSMESH.Reboot'
        ], 206);
        $this->EnableAction('Reboot');

        // --- Diagnostik (900+) ---
        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Clock'
        ], 999);
    }

    /**
     * Registers node-specific variables for all configured nodes.
     */
    private function RegisterNodeVariables(): void
    {
        $maxNodes = 4; // Register for max 4 always in Create()
        for ($n = 1; $n <= $maxNodes; $n++) {
            $basePos = 10 + (($n - 1) * 15);
            $monBase = 100 + (($n - 1) * 10);

            // Node Status
            $this->RegisterVariableBoolean("Node{$n}_Online", "Node {$n}: Status", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Network'
            ], $basePos);

            $this->RegisterVariableString("Node{$n}_Name", "Node {$n}: Name", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Information'
            ], $basePos + 1);

            $this->RegisterVariableString("Node{$n}_IP", "Node {$n}: IP-Adresse", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Distance'
            ], $basePos + 2);

            $this->RegisterVariableString("Node{$n}_Firmware", "Node {$n}: Firmware", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Gear'
            ], $basePos + 3);

            $this->RegisterVariableInteger("Node{$n}_Clients", "Node {$n}: Clients", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'People'
            ], $basePos + 4);

            $this->RegisterVariableString("Node{$n}_Uptime", "Node {$n}: Uptime", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Clock'
            ], $basePos + 5);

            // System Monitoring
            $this->RegisterVariableFloat("Node{$n}_CPU", "Node {$n}: CPU", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Gauge',
                'SUFFIX'       => ' %'
            ], $monBase);

            $this->RegisterVariableFloat("Node{$n}_RAM", "Node {$n}: RAM", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Gauge',
                'SUFFIX'       => ' %'
            ], $monBase + 1);

            $this->RegisterVariableFloat("Node{$n}_TempCPU", "Node {$n}: CPU Temperatur", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Temperature',
                'SUFFIX'       => ' °C'
            ], $monBase + 2);

            $this->RegisterVariableFloat("Node{$n}_Temp2G", "Node {$n}: 2.4 GHz Temperatur", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Temperature',
                'SUFFIX'       => ' °C'
            ], $monBase + 3);

            $this->RegisterVariableFloat("Node{$n}_Temp5G", "Node {$n}: 5 GHz Temperatur", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Temperature',
                'SUFFIX'       => ' °C'
            ], $monBase + 4);

            $this->RegisterVariableFloat("Node{$n}_Temp6G", "Node {$n}: 6 GHz Temperatur", [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Temperature',
                'SUFFIX'       => ' °C'
            ], $monBase + 5);
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

        // ClientList as HTMLBox display
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ClientList'), [
            'PRESENTATION'  => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON'          => 'Database',
            'COLOR'         => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE'  => 1, // HTML display
            'PREVIEW_STYLE' => 0,
            'SHOW_PREVIEW'  => false,
            'OPTIONS'       => '[]'
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
        $varID = @$this->GetIDForIdent("Node{$n}_Online");
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

        // Parse clients
        $this->ParseClients($data);

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
                $this->SetValue('Node1_TempCPU', (float)$temps['cpu']);
            }
            if (isset($temps['2g'])) {
                $this->SetValue('Node1_Temp2G', (float)$temps['2g']);
            }
            if (isset($temps['5g'])) {
                $this->SetValue('Node1_Temp5G', (float)$temps['5g']);
            }
            if (isset($temps['6g'])) {
                $this->SetValue('Node1_Temp6G', (float)$temps['6g']);
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
                if ($val <= 0) {
                    continue;
                }
                if (str_contains($key, 'cpu')) {
                    $temps['cpu'] = $val;
                } elseif (str_contains($key, '2.4') || str_contains($key, '2g')) {
                    $temps['2g'] = $val;
                } elseif (str_contains($key, '5g') || str_contains($key, '5 g')) {
                    if (!isset($temps['5g'])) {
                        $temps['5g'] = $val;
                    }
                } elseif (str_contains($key, '6g') || str_contains($key, '6 g')) {
                    $temps['6g'] = $val;
                }
            }
            return $temps;
        }

        // Try line-based format: "1 = 55\n2 = 48\n..."
        // Or: "cpu_temperature:55&2.4 GHz:48&5 GHz:52"
        preg_match_all('/(\d+(?:\.\d+)?)\s*[&°]?\s*/', $response, $matches);
        if (!empty($matches[1])) {
            $values = array_values(array_filter($matches[1], fn($v) => (float)$v > 0 && (float)$v < 120));
            if (count($values) >= 1) {
                $temps['cpu'] = (float)$values[0];
            }
            if (count($values) >= 2) {
                $temps['2g'] = (float)$values[1];
            }
            if (count($values) >= 3) {
                $temps['5g'] = (float)$values[2];
            }
            if (count($values) >= 4) {
                $temps['6g'] = (float)$values[3];
            }
        }

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

            $this->SetValue("Node{$n}_Online", $isOnline);
            $this->SetValue("Node{$n}_Name", (string)$alias);
            $this->SetValue("Node{$n}_IP", (string)$ip);
            $this->SetValue("Node{$n}_Firmware", (string)$fwVer);
        }

        // Clear unused nodes
        for ($n = count($nodeList) + 1; $n <= $maxNodes; $n++) {
            $this->SetValue("Node{$n}_Online", false);
            $this->SetValue("Node{$n}_Name", 'Nicht konfiguriert');
            $this->SetValue("Node{$n}_IP", '');
            $this->SetValue("Node{$n}_Firmware", '');
            $this->SetValue("Node{$n}_Clients", 0);
        }

        $this->SetValue('MeshNodesOnline', $onlineCount);

        // Save MAC map for client assignment
        $this->SetBuffer(self::ASUS_NODE_MAC_MAP, json_encode($macMap));
    }

    /**
     * Parses client list and builds HTML table.
     */
    private function ParseClients(array $data): void
    {
        $clientList = $data['get_clientlist'] ?? [];
        if (!is_array($clientList)) {
            $this->SendDebug('ParseClients', 'Keine Client-Liste erhalten', 0);
            return;
        }

        $macMap = json_decode($this->GetBuffer(self::ASUS_NODE_MAC_MAP), true);
        if (!is_array($macMap)) {
            $macMap = [];
        }

        // Reverse map: MAC -> Node Index
        $macToNode = [];
        foreach ($macMap as $nodeIndex => $mac) {
            $macToNode[strtoupper((string)$mac)] = (int)$nodeIndex;
        }

        $clients = [];
        $nodeClientCount = [];
        $maxNodes = $this->ReadPropertyInteger('MaxNodes');
        for ($n = 1; $n <= $maxNodes; $n++) {
            $nodeClientCount[$n] = 0;
        }

        // Client list format can vary; iterate over entries
        foreach ($clientList as $mac => $info) {
            if (!is_array($info)) {
                continue;
            }

            // Skip the 'maclist' and 'ClientAPILevel' meta entries
            if ($mac === 'maclist' || $mac === 'ClientAPILevel') {
                continue;
            }

            $clientName = $info['name'] ?? ($info['nickName'] ?? $mac);
            $clientIP = $info['ip'] ?? '';
            $isOnline = ($info['isOnline'] ?? '0') === '1';
            $rssi = $info['rssi'] ?? '';
            $connType = $info['isWL'] ?? '0'; // 0=wired, 1=2.4G, 2=5G, 3=5G-2, 4=6G

            // Determine which node this client is connected to
            $connectedNodeMAC = strtoupper((string)($info['isGateway'] ?? ''));
            if (empty($connectedNodeMAC)) {
                // Try to find via other fields
                $connectedNodeMAC = strtoupper((string)($info['ap'] ?? ''));
            }

            $nodeIndex = $macToNode[$connectedNodeMAC] ?? 1; // Default to controller
            if ($isOnline && isset($nodeClientCount[$nodeIndex])) {
                $nodeClientCount[$nodeIndex]++;
            }

            if ($isOnline) {
                $connTypeLabel = match ((string)$connType) {
                    '0' => 'LAN',
                    '1' => '2.4 GHz',
                    '2' => '5 GHz',
                    '3' => '5 GHz-2',
                    '4' => '6 GHz',
                    default => 'WiFi'
                };

                $clients[] = [
                    'name'     => (string)$clientName,
                    'ip'       => (string)$clientIP,
                    'mac'      => strtoupper((string)$mac),
                    'node'     => $nodeIndex,
                    'connType' => $connTypeLabel,
                    'rssi'     => (string)$rssi,
                ];
            }
        }

        // Update node client counts
        foreach ($nodeClientCount as $n => $count) {
            $this->SetValue("Node{$n}_Clients", $count);
        }

        // Total clients
        $this->SetValue('TotalClients', count($clients));

        // Build HTML table
        $html = $this->BuildClientHTML($clients);
        $this->SetValue('ClientList', $html);
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
                    $this->SetValue('Node1_CPU', round($totalUsage / $coreCount, 1));
                } elseif (isset($cpuData['cpu_total'])) {
                    $this->SetValue('Node1_CPU', (float)$cpuData['cpu_total']);
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
                    $this->SetValue('Node1_RAM', round(($used / $total) * 100, 1));
                }
            }
        }

        // Uptime
        if (isset($data['uptime'])) {
            $uptime = (string)$data['uptime'];
            // Format the uptime string
            if (preg_match('/(\d+)\s*days?,?\s*(\d+):(\d+):(\d+)/', $uptime, $m)) {
                $formatted = "{$m[1]}d {$m[2]}h {$m[3]}m";
                $this->SetValue('Node1_Uptime', $formatted);
            } else {
                $this->SetValue('Node1_Uptime', $uptime);
            }
        }
    }

    /**
     * Parses current states of controllable features.
     */
    private function ParseControlStates(array $data): void
    {
        // LED state
        if (isset($data['led_val'])) {
            $this->SetValue('LED', (int)$data['led_val']);
        }

        // WiFi radios
        if (isset($data['wl0_radio'])) {
            $this->SetValue('WiFi_2G', (int)$data['wl0_radio']);
        }
        if (isset($data['wl1_radio'])) {
            $this->SetValue('WiFi_5G1', (int)$data['wl1_radio']);
        }
        if (isset($data['wl2_radio'])) {
            $this->SetValue('WiFi_5G2', (int)$data['wl2_radio']);
        }
        if (isset($data['wl3_radio'])) {
            $this->SetValue('WiFi_6G', (int)$data['wl3_radio']);
        }

        // Guest WiFi (check if any guest interface is enabled)
        $guest24 = (int)($data['wl0.1_bss_enabled'] ?? 0);
        $guest5 = (int)($data['wl1.1_bss_enabled'] ?? 0);
        $this->SetValue('GuestWiFi', ($guest24 > 0 || $guest5 > 0) ? 1 : 0);
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
    // HTML Builder
    // =========================================================================

    /**
     * Builds a styled HTML table of connected clients.
     */
    private function BuildClientHTML(array $clients): string
    {
        if (empty($clients)) {
            return '<div style="padding:10px;color:#888;">Keine Geräte verbunden</div>';
        }

        // Sort by node, then by name
        usort($clients, function ($a, $b) {
            if ($a['node'] !== $b['node']) {
                return $a['node'] <=> $b['node'];
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $macMap = json_decode($this->GetBuffer(self::ASUS_NODE_MAC_MAP), true);

        $html = '<style>
            .mesh-table { width:100%; border-collapse:collapse; font-family:Arial,sans-serif; font-size:13px; }
            .mesh-table th { background:#1a1a2e; color:#eee; padding:8px 10px; text-align:left; border-bottom:2px solid #16213e; }
            .mesh-table td { padding:6px 10px; border-bottom:1px solid #2a2a4a; color:#ccc; }
            .mesh-table tr:hover td { background:#16213e; }
            .mesh-table .node-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:bold; color:#fff; }
            .node-1 { background:#0066cc; }
            .node-2 { background:#cc6600; }
            .node-3 { background:#00aa66; }
            .node-4 { background:#aa00aa; }
            .conn-type { display:inline-block; padding:2px 6px; border-radius:8px; font-size:11px; background:#2a2a4a; color:#88aacc; }
        </style>';

        $html .= '<table class="mesh-table">';
        $html .= '<tr><th>Gerät</th><th>IP</th><th>Node</th><th>Verbindung</th></tr>';

        foreach ($clients as $client) {
            $nodeNum = $client['node'];
            $nodeName = '';
            if (is_array($macMap) && isset($macMap[$nodeNum])) {
                $nodeName = @$this->GetValue("Node{$nodeNum}_Name");
            }
            if (empty($nodeName)) {
                $nodeName = "Node {$nodeNum}";
            }

            $html .= '<tr>';
            $html .= '<td><b>' . htmlspecialchars($client['name']) . '</b><br><small style="color:#666">' . htmlspecialchars($client['mac']) . '</small></td>';
            $html .= '<td>' . htmlspecialchars($client['ip']) . '</td>';
            $html .= '<td><span class="node-badge node-' . $nodeNum . '">' . htmlspecialchars($nodeName) . '</span></td>';
            $html .= '<td><span class="conn-type">' . htmlspecialchars($client['connType']) . '</span></td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
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
