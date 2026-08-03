<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';

class MikroTikRouter extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    use SmartHttp_Trait;

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
            'ICON' => 'Gauge',
            'SUFFIX' => ' %',
            'SHOW_PREVIEW' => true
        ], 1);
        $this->RegisterVariableFloat('RAM', 'RAM', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Gauge',
            'SUFFIX' => ' %',
            'SHOW_PREVIEW' => true
        ], 2);
        $this->RegisterVariableFloat('Temperature', 'Temperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Temperature',
            'SUFFIX' => ' Ã‚Â°C',
            'SHOW_PREVIEW' => true
        ], 3);
        $this->RegisterVariableString('BoardName', 'Modell', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information',
            'SHOW_PREVIEW' => true
        ], 4);
        $this->RegisterVariableString('FirmwareVersion', 'Firmware', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information',
            'SHOW_PREVIEW' => true
        ], 5);
        $this->RegisterVariableString('Uptime', 'Uptime', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Clock',
            'SHOW_PREVIEW' => true
        ], 6);

        $updateOptions = json_encode([
            ['Value' => false, 'Caption' => 'Aktuell', 'IconValue' => 'Ok', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC44, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC44],
            ['Value' => true, 'Caption' => 'VerfÃƒÂ¼gbar', 'IconValue' => 'Repeat', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF8800, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF8800]
        ]);
        $this->RegisterVariableBoolean('UpdateAvailable', 'OS-Update verfÃƒÂ¼gbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Repeat',
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $updateOptions
        ], 10);
        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Clock'
        ], 999);

        $actionPres = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'Execute',
            'OPTIONS'      => json_encode([
                ['Value' => 0, 'Caption' => 'Bereit', 'IconActive' => true, 'IconValue' => 'Ok', 'Color' => 0x00CC44],
                ['Value' => 1, 'Caption' => 'AusfÃƒÂ¼hren!', 'IconActive' => true, 'IconValue' => 'Warning', 'Color' => 0xFF4444]
            ])
        ];

        // Action Variables
        $this->RegisterVariableInteger('ActionCheckUpdate', 'Auf Updates prÃƒÂ¼fen', $actionPres, 100);
        $this->EnableAction('ActionCheckUpdate');

        $this->RegisterVariableInteger('ActionInstallOS', 'OS-Update installieren', $actionPres, 101);
        $this->EnableAction('ActionInstallOS');

        $this->RegisterVariableInteger('ActionUpgradeFW', 'Firmware upgraden', $actionPres, 102);
        $this->EnableAction('ActionUpgradeFW');

        $this->RegisterVariableInteger('ActionReboot', 'Neustarten', $actionPres, 103);
        $this->EnableAction('ActionReboot');
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

        // Migration: Delete legacy profile
        if (IPS_VariableProfileExists('MIKROTIK.Action')) {
            $vars = ['ActionCheckUpdate', 'ActionInstallOS', 'ActionUpgradeFW', 'ActionReboot'];
            foreach ($vars as $v) {
                IPS_SetVariableCustomProfile($this->GetIDForIdent($v), '');
            }
            IPS_DeleteVariableProfile('MIKROTIK.Action');
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

        $res = $this->SendRestRequest('/rest/system/package/update/check-for-updates', 'POST');
        if ($res !== null) {
            $status = $res['status'] ?? '';
            if (strpos(strtolower($status), 'new version is available') !== false) {
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
