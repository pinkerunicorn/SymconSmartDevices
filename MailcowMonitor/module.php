<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class MailcowMonitor extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('URL', 'https://mail.ubyte.pink');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyInteger('UpdateInterval', 3600);
        
        $this->RegisterPropertyBoolean('MonitorContainers', true);
        $this->RegisterPropertyBoolean('MonitorStorage', true);
        $this->RegisterPropertyBoolean('MonitorMailQueue', true);

        $this->RegisterPropertyInteger('AlarmStorageThreshold', 90);
        $this->RegisterPropertyInteger('AlarmMailQueueThreshold', 10);
        $this->RegisterPropertyBoolean('AlarmOnUpdate', false);

        $this->RegisterTimer('UpdateTimer', 0, 'MAILCOW_Update($_IPS[\'TARGET\']);');

        $this->DA_RegisterAvailability(900);

        $this->RegisterVariableString('Version', 'Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ], 1);

        $this->RegisterVariableBoolean('UpdateAvailable', 'Update verfügbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ], 2);

        $this->RegisterVariableInteger('QuarantineCount', 'Quarantäne Einträge', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Warning'
        ], 3);

        $this->RegisterVariableString('LastUpdate', 'Letztes Update', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Clock'
        ], 4);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->DA_ApplyPresentation();

        $updateOptions = json_encode([
            [
                'Value' => false, 'Caption' => 'Aktuell', 'IconValue' => 'check', 'IconActive' => true,
                'ColorActive' => true, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false,
                'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00
            ],
            [
                'Value' => true, 'Caption' => 'Update verfügbar', 'IconValue' => 'Warning', 'IconActive' => true,
                'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
                'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000
            ]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('UpdateAvailable'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON'         => 'Information',
            'COLOR'        => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS'      => $updateOptions
        ]);

        $this->MaintainVariable('ContainersRunning', 'Container Status', 0, '', 5, $this->ReadPropertyBoolean('MonitorContainers'));
        if ($this->ReadPropertyBoolean('MonitorContainers')) {
            $containerOptions = json_encode([
                [
                    'Value' => false, 'Caption' => 'Fehler', 'IconValue' => 'Warning', 'IconActive' => true,
                    'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
                    'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000
                ],
                [
                    'Value' => true, 'Caption' => 'Alle OK', 'IconValue' => 'check', 'IconActive' => true,
                    'ColorActive' => true, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false,
                    'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00
                ]
            ]);
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('ContainersRunning'), [
                'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                'ICON'         => 'Network',
                'COLOR'        => -1,
                'CONTENT_COLOR' => -1,
                'DISPLAY_TYPE' => 0,
                'PREVIEW_STYLE' => 1,
                'SHOW_PREVIEW' => true,
                'OPTIONS'      => $containerOptions
            ]);
        }

        $this->MaintainVariable('StorageUsage', 'vMail Auslastung', 1, '', 6, $this->ReadPropertyBoolean('MonitorStorage'));
        if ($this->ReadPropertyBoolean('MonitorStorage')) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('StorageUsage'), [
                'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                'ICON'         => 'Database',
                'SUFFIX'       => '%'
            ]);
        }

        $this->MaintainVariable('MailQueue', 'Mail Warteschlange', 1, '', 7, $this->ReadPropertyBoolean('MonitorMailQueue'));
        if ($this->ReadPropertyBoolean('MonitorMailQueue')) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('MailQueue'), [
                'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                'ICON'         => 'Mail'
            ]);
        }

        if ($this->ReadPropertyString('URL') != '' && $this->ReadPropertyString('APIKey') != '') {
            $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);
            $this->Update();
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }
    }

    public function Update(): void
    {
        $url = rtrim($this->ReadPropertyString('URL'), '/');
        $apiKey = $this->ReadPropertyString('APIKey');

        if ($url == '' || $apiKey == '') {
            return;
        }
        
        $errors = [];

        // Fetch Mailcow Version
        $ch = curl_init($url . '/api/v1/get/status/version');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode != 200) {
            $this->DA_SetAvailable(false, 'API Fehler: Version konnte nicht geladen werden');
            return;
        }

        $data = json_decode($response, true);
        if (isset($data['version'])) {
            $localVersion = $data['version'];
            $this->SetValue('Version', $localVersion);
        } else {
            $this->DA_SetAvailable(false, 'Ungültige API Antwort (Version)');
            return;
        }

        // Fetch Quarantine Count
        $ch = curl_init($url . '/api/v1/get/quarantine/all');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $httpCode == 200) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                $this->SetValue('QuarantineCount', count($data));
            }
        }

        // Fetch Container Status
        if ($this->ReadPropertyBoolean('MonitorContainers')) {
            $ch = curl_init($url . '/api/v1/get/status/containers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode == 200) {
                $data = json_decode($response, true);
                $allRunning = true;
                if (is_array($data)) {
                    foreach ($data as $container) {
                        if (isset($container['state']) && $container['state'] !== 'running') {
                            $allRunning = false;
                            $errors[] = 'Container ausgefallen: ' . $container['container'];
                        }
                    }
                    $this->SetValue('ContainersRunning', $allRunning);
                }
            }
        }

        // Fetch Storage Usage
        if ($this->ReadPropertyBoolean('MonitorStorage')) {
            $ch = curl_init($url . '/api/v1/get/status/vmail');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode == 200) {
                $data = json_decode($response, true);
                if (isset($data['used_percent'])) {
                    $percent = (int)str_replace('%', '', $data['used_percent']);
                    $this->SetValue('StorageUsage', $percent);
                    if ($percent >= $this->ReadPropertyInteger('AlarmStorageThreshold')) {
                        $errors[] = 'Festplatte fast voll (' . $percent . '%)';
                    }
                }
            }
        }

        // Fetch Mail Queue
        if ($this->ReadPropertyBoolean('MonitorMailQueue')) {
            $ch = curl_init($url . '/api/v1/get/mailq/all');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode == 200) {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    $queueCount = count($data);
                    $this->SetValue('MailQueue', $queueCount);
                    if ($queueCount >= $this->ReadPropertyInteger('AlarmMailQueueThreshold')) {
                        $errors[] = 'Mail-Warteschlange hoch (' . $queueCount . ')';
                    }
                }
            }
        }

        // Check for Updates on Github
        $ch = curl_init('https://api.github.com/repos/mailcow/mailcow-dockerized/releases/latest');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: IP-Symcon-MailcowMonitor']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $githubResponse = curl_exec($ch);
        $githubHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($githubResponse !== false && $githubHttpCode == 200) {
            $githubData = json_decode($githubResponse, true);
            if (isset($githubData['tag_name'])) {
                $latestVersion = $githubData['tag_name'];
                
                // Compare local version vs github latest version
                if (strcmp($latestVersion, $localVersion) > 0) {
                    $this->SetValue('UpdateAvailable', true);
                    if ($this->ReadPropertyBoolean('AlarmOnUpdate')) {
                        $errors[] = 'Update verfügbar (' . $latestVersion . ')';
                    }
                } else {
                    $this->SetValue('UpdateAvailable', false);
                }
            }
        }

        $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));
        
        if (count($errors) > 0) {
            $this->DA_SetAvailable(false, implode(' | ', $errors));
        } else {
            $this->DA_SetAvailable(true);
        }
    }
}
