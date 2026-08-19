<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class MikroTikRouter extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    use SmartHttp_Trait;
    use DeviceRegistration_Trait;

    public function Create(): void
    {
        parent::Create();

        // Register Properties
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', 'admin');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyBoolean('UseHTTPS', false);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyBoolean('AutoCheckUpdates', true);
        $this->RegisterPropertyInteger('AutoCheckInterval', 24);

        // DeviceAvailability
        $this->DA_RegisterAvailability(900);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'MIKROTIK_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CheckUpdateTimer', 0, 'MIKROTIK_CheckForUpdate($_IPS[\'TARGET\']);');

        // Monitoring Variables (Read-Only)
        $this->RegisterVariableFloat('CPU', 'CPU', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'microchip',
            'SUFFIX' => ' %',
            'SHOW_PREVIEW' => true
        ], 1);
        $this->RegisterVariableFloat('RAM', 'RAM', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'microchip',
            'SUFFIX' => ' %',
            'SHOW_PREVIEW' => true
        ], 2);
        $this->RegisterVariableFloat('Temperature', 'Temperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'temperature-half',
            'SUFFIX' => ' °C',
            'SHOW_PREVIEW' => true
        ], 3);
        $this->RegisterVariableString('BoardName', 'Modell', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'microchip',
            'SHOW_PREVIEW' => true
        ], 4);
        $this->RegisterVariableString('FirmwareVersion', 'OS-Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'microchip',
            'SHOW_PREVIEW' => true
        ], 5);
        $this->RegisterVariableString('RouterBoardFirmware', 'Firmware-Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'microchip',
            'SHOW_PREVIEW' => true
        ], 6);
        $this->RegisterVariableString('Uptime', 'Uptime', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'clock',
            'SHOW_PREVIEW' => true
        ], 7);

        $updateOptions = json_encode([
            ['Value' => false, 'Caption' => 'Aktuell', 'IconValue' => 'circle-check', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC44, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC44],
            ['Value' => true, 'Caption' => 'Verfügbar', 'IconValue' => 'rotate', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF8800, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF8800]
        ]);
        $this->RegisterVariableBoolean('UpdateAvailable', 'OS-Update verfügbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'rotate',
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $updateOptions
        ], 10);
        $this->RegisterVariableBoolean('FirmwareUpdateAvailable', 'Firmware-Update verfügbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'rotate',
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $updateOptions
        ], 11);
        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'clock-rotate-left'
        ], 999);

        if (!IPS_VariableProfileExists('MIKROTIK.Action')) {
            IPS_CreateVariableProfile('MIKROTIK.Action', 1);
            IPS_SetVariableProfileIcon('MIKROTIK.Action', 'Execute');
            IPS_SetVariableProfileAssociation('MIKROTIK.Action', 0, 'Bereit', 'Ok', 0x00CC44);
            IPS_SetVariableProfileAssociation('MIKROTIK.Action', 1, 'Ausführen!', 'Warning', 0xFF4444);
        }

        // Action Variables
        $this->RegisterVariableInteger('ActionCheckUpdate', 'Auf Updates prüfen', [
            'PROFILE' => 'MIKROTIK.Action',
            'ICON' => 'arrows-rotate'
        ], 100);
        $this->EnableAction('ActionCheckUpdate');

        $this->RegisterVariableInteger('ActionInstallOS', 'OS-Update installieren', [
            'PROFILE' => 'MIKROTIK.Action',
            'ICON' => 'download'
        ], 101);
        $this->EnableAction('ActionInstallOS');

        $this->RegisterVariableInteger('ActionUpgradeFW', 'Firmware upgraden', [
            'PROFILE' => 'MIKROTIK.Action',
            'ICON' => 'download'
        ], 102);
        $this->EnableAction('ActionUpgradeFW');

        $this->RegisterVariableInteger('ActionReboot', 'Neustarten', [
            'PROFILE' => 'MIKROTIK.Action',
            'ICON' => 'power-off'
        ], 103);
        $this->EnableAction('ActionReboot');
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



        if (empty($this->ReadPropertyString('Host'))) {
            $this->SetStatus(104);
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetTimerInterval('CheckUpdateTimer', 0);
            return;
        $this->DR_Register('DevicesGenericSensor');
        }

        $this->SetStatus(102);
        if ($this->ReadPropertyInteger('UpdateInterval') > 0) {
            $this->SetTimerInterval('UpdateTimer', 2000); // Async start
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }
        
        if ($this->ReadPropertyBoolean('AutoCheckUpdates') && $this->ReadPropertyInteger('AutoCheckInterval') > 0) {
            $this->SetTimerInterval('CheckUpdateTimer', 3000); // Async start
        } else {
            $this->SetTimerInterval('CheckUpdateTimer', 0);
        }

    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'ActionCheckUpdate':
                if ($Value == 1) {
                    $this->CheckForUpdate();
                    $this->SetValue($Ident, 0);
                }
                break;
            case 'ActionInstallOS':
                if ($Value == 1) {
                    $this->InstallOSUpdate();
                    $this->SetValue($Ident, 0);
                }
                break;
            case 'ActionUpgradeFW':
                if ($Value == 1) {
                    $this->UpgradeFirmware();
                    $this->SetValue($Ident, 0);
                }
                break;
            case 'ActionReboot':
                if ($Value == 1) {
                    $this->RebootRouter();
                    $this->SetValue($Ident, 0);
                }
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function Update(): void
    {
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 1000);
        }

        // 1. Get System Resources (CPU, RAM, Uptime, Version)
        $resources = $this->SendRestRequest('/rest/system/resource');
        if ($resources === null) {
            $this->DA_SetAvailable(false, 'API nicht erreichbar');
            return;
        }
        $this->DA_SetAvailable(true);

        if (isset($resources['cpu-load'])) {
            $this->SetValue('CPU', (float)$resources['cpu-load']);
        }
        if (isset($resources['free-memory']) && isset($resources['total-memory'])) {
            $total = (float)$resources['total-memory'];
            $free = (float)$resources['free-memory'];
            if ($total > 0) {
                $usage = (($total - $free) / $total) * 100;
                $this->SetValue('RAM', $usage);
            }
        }
        if (isset($resources['uptime'])) {
            $this->SetValue('Uptime', (string)$resources['uptime']);
        }
        if (isset($resources['board-name'])) {
            $this->SetValue('BoardName', (string)$resources['board-name']);
        }
        if (isset($resources['version'])) {
            $this->SetValue('FirmwareVersion', (string)$resources['version']);
        }

        // 2. Get Temperature
        $health = $this->SendRestRequest('/rest/system/health');
        if (is_array($health)) {
            foreach ($health as $item) {
                if (isset($item['name']) && strpos($item['name'], 'temperature') !== false) {
                    $this->SetValue('Temperature', (float)$item['value']);
                    break;
                }
            }
        }

        // 3. Get RouterBOARD Info
        $routerboard = $this->SendRestRequest('/rest/system/routerboard');
        if ($routerboard !== null) {
            $rbData = isset($routerboard[0]) ? $routerboard[0] : $routerboard;
            
            if (isset($rbData['current-firmware'])) {
                $this->SetValue('RouterBoardFirmware', (string)$rbData['current-firmware']);
            }
            
            if (isset($rbData['current-firmware']) && isset($rbData['upgrade-firmware'])) {
                if ($rbData['current-firmware'] !== $rbData['upgrade-firmware']) {
                    $this->SetValue('FirmwareUpdateAvailable', true);
                } else {
                    $this->SetValue('FirmwareUpdateAvailable', false);
                }
            }
        }

        $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));
    }

    public function TestConnection(): string
    {
        $res = $this->SendRestRequest('/rest/system/identity');
        if ($res === null) {
            return "Verbindung fehlgeschlagen!";
        }
        $name = $res['name'] ?? 'Unbekannt';
        return "Verbindung erfolgreich! Router: {$name}";
    }

    public function DumpDebug(): string
    {
        $res = $this->SendRestRequest('/rest/system/resource');
        return print_r($res, true);
    }

    public function CheckForUpdate(): void
    {
        if ($this->ReadPropertyBoolean('AutoCheckUpdates')) {
            $interval = $this->ReadPropertyInteger('AutoCheckInterval');
            if ($interval > 0) {
                $this->SetTimerInterval('CheckUpdateTimer', $interval * 3600 * 1000);
            }
        }

        $this->SendRestRequest('/rest/system/package/update/check-for-updates', 'POST');
        
        // Kurz warten, damit der Router die Info laden kann
        IPS_Sleep(2000);

        $res = $this->SendRestRequest('/rest/system/package/update', 'GET');
        if ($res !== null) {
            // Wenn die API ein Array mit einem Element zurückgibt (z.B. bei print commands)
            $data = isset($res[0]) ? $res[0] : $res;
            
            $installed = $data['installed-version'] ?? '';
            $latest = $data['latest-version'] ?? '';
            
            if ($installed !== '' && $latest !== '' && $installed !== $latest) {
                $this->SetValue('UpdateAvailable', true);
            } else {
                $this->SetValue('UpdateAvailable', false);
            }
        }
    }

    private function InstallOSUpdate(): void
    {
        $this->SendRestRequest('/rest/system/package/update/install', 'POST');
    }

    private function UpgradeFirmware(): void
    {
        $this->SendRestRequest('/rest/system/routerboard/upgrade', 'POST');
    }

    private function RebootRouter(): void
    {
        $this->SendRestRequest('/rest/system/reboot', 'POST');
    }

    private function SendRestRequest(string $endpoint, string $method = 'GET', array $payload = null): ?array
    {
        $host = $this->ReadPropertyString('Host');
        if (empty($host)) {
            return null;
        }
        $user = $this->ReadPropertyString('Username');
        $pass = $this->ReadPropertyString('Password');
        $useHTTPS = $this->ReadPropertyBoolean('UseHTTPS');

        $protocol = $useHTTPS ? 'https' : 'http';
        $url = "{$protocol}://{$host}{$endpoint}";

        $headers = [
            'Authorization: Basic ' . base64_encode("{$user}:{$pass}")
        ];

        return $this->HttpRequest($url, $method, $headers, $payload, 10, true);
    }
}
