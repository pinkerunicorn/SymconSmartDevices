<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class MikroTikRouter extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();

        // Register Properties
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', 'admin');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyBoolean('UseHTTPS', false);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // DeviceAvailability
        $this->DA_RegisterAvailability(900);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'MIKROTIK_Update($_IPS[\'TARGET\']);');

        // Monitoring Variables (Read-Only)
        $this->RegisterVariableFloat('CPU', 'CPU', '', 1);
        $this->RegisterVariableFloat('RAM', 'RAM', '', 2);
        $this->RegisterVariableFloat('Temperature', 'Temperatur', '', 3);
        $this->RegisterVariableString('FirmwareVersion', 'Firmware', '', 4);
        $this->RegisterVariableString('Uptime', 'Uptime', '', 5);
        $this->RegisterVariableBoolean('UpdateAvailable', 'OS-Update verfügbar', '', 10);
        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', '', 999);

        // Action Profiles
        if (!IPS_VariableProfileExists('MIKROTIK.Action')) {
            IPS_CreateVariableProfile('MIKROTIK.Action', 1); // Integer
            IPS_SetVariableProfileIcon('MIKROTIK.Action', 'Execute');
            IPS_SetVariableProfileAssociation('MIKROTIK.Action', 0, 'Bereit', 'Ok', 0x00CC44);
            IPS_SetVariableProfileAssociation('MIKROTIK.Action', 1, 'Ausführen!', 'Warning', 0xFF4444);
        }

        // Action Variables
        $this->RegisterVariableInteger('ActionCheckUpdate', 'Auf Updates prüfen', 'MIKROTIK.Action', 100);
        $this->EnableAction('ActionCheckUpdate');

        $this->RegisterVariableInteger('ActionInstallOS', 'OS-Update installieren', 'MIKROTIK.Action', 101);
        $this->EnableAction('ActionInstallOS');

        $this->RegisterVariableInteger('ActionUpgradeFW', 'Firmware upgraden', 'MIKROTIK.Action', 102);
        $this->EnableAction('ActionUpgradeFW');

        $this->RegisterVariableInteger('ActionReboot', 'Neustarten', 'MIKROTIK.Action', 103);
        $this->EnableAction('ActionReboot');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Custom Presentations
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('CPU'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Gauge',
            'SUFFIX' => ' %',
            'SHOW_PREVIEW' => true
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('RAM'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Gauge',
            'SUFFIX' => ' %',
            'SHOW_PREVIEW' => true
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Temperature'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Temperature',
            'SUFFIX' => ' °C',
            'SHOW_PREVIEW' => true
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('FirmwareVersion'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Information',
            'SHOW_PREVIEW' => true
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Uptime'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Clock',
            'SHOW_PREVIEW' => true
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('LastUpdate'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Clock'
        ]);

        $updateOptions = json_encode([
            ['Value' => false, 'Caption' => 'Aktuell', 'IconValue' => 'Ok', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC44, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC44],
            ['Value' => true, 'Caption' => 'Verfügbar', 'IconValue' => 'Repeat', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF8800, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF8800]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('UpdateAvailable'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Repeat',
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $updateOptions
        ]);

        $this->DA_ApplyPresentation();

        if (empty($this->ReadPropertyString('Host'))) {
            $this->SetStatus(104);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $this->SetStatus(102);
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);
        $this->Update();
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
        if (isset($resources['version'])) {
            $this->SetValue('FirmwareVersion', (string)$resources['version']);
        }

        // 2. Get Temperature
        $health = $this->SendRestRequest('/rest/system/health');
        if (is_array($health)) {
            foreach ($health as $item) {
                if (isset($item['name']) && $item['name'] === 'temperature') {
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

    private function CheckForUpdate(): void
    {
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

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_USERPWD, "{$user}:{$pass}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($payload !== null) {
            $json = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($json)]);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('REST Error', "Code: {$httpCode} | Response: {$response}", 0);
            return null;
        }

        if (empty($response)) {
            return [];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SendDebug('REST JSON Error', json_last_error_msg(), 0);
            return null;
        }

        return $data;
    }
}
