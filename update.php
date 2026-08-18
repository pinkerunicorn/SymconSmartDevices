<?php
$modules = [
    'GardenaIrrigationControl', 'GardenaSensor', 'GardenaValve',
    'GoogleSonosTTS',
    'MailcowMonitor', 'MikroTikRouter',
    'PixelblazeController',
    'SmartFountain',
    'TedeeLock',
    'VestaboardGenerator',
    'WithingsDevice',
    'WLEDDevice',
    'XeroxPrinter'
];

$basePath = 'C:/Users/grass/Documents/Symcon/SymconSmartDevices';

foreach ($modules as $mod) {
    $file = $basePath . '/' . $mod . '/module.php';
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($file);
    $originalContent = $content;
    
    // 1. Add require_once
    if (strpos($content, 'Trait_DeviceRegistration.php') === false) {
        $content = preg_replace('/(declare\(strict_types=1\);)/i', "$1\n\nrequire_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';", $content, 1);
    }
    
    // 2. Add use trait
    if (strpos($content, 'use DeviceRegistration_Trait;') === false) {
        $content = preg_replace('/(class\s+[A-Za-z0-9_]+\s+extends\s+[A-Za-z0-9_]+[^{]*\{)/i', "$1\n    use DeviceRegistration_Trait;", $content, 1);
    }
    
    $deviceType = 'DevicesGenericSensor';
    
    // Remove existing DR_Register and DR_Unregister to avoid duplicates or wrong places
    $content = preg_replace('/^\s*\$this->DR_Register\([^\)]+\);\s*$/m', '', $content);
    $content = preg_replace('/^\s*\$this->DR_Unregister\(\);\s*$/m', '', $content);
    
    // 3. Add to Create() am Ende
    $createRegex = '/(public\s+function\s+Create\s*\(\)\s*(?::\s*void\s*)?\{.*?)(\n\s*\})/is';
    if (preg_match($createRegex, $content)) {
        $content = preg_replace($createRegex, "$1\n        \$this->DR_Register('$deviceType');$2", $content, 1);
    } else {
        echo "No Create method in $mod\n";
    }
    
    // 4. Add or update Destroy()
    if (strpos($content, 'public function Destroy()') === false && strpos($content, 'public function Destroy (') === false && strpos($content, 'public function Destroy:') === false) {
        $destroyFunc = "\n    public function Destroy(): void\n    {\n        parent::Destroy();\n        \$this->DR_Unregister();\n    }\n";
        // Insert after Create method
        $content = preg_replace('/(public\s+function\s+Create\s*\(\)\s*(?::\s*void\s*)?\{.*?\n\s*\})/is', "$1\n$destroyFunc", $content, 1);
    } else {
        $destroyRegex = '/(public\s+function\s+Destroy\s*\(\)\s*(?::\s*void\s*)?\{.*?)(\n\s*\})/is';
        if (preg_match($destroyRegex, $content)) {
            $content = preg_replace($destroyRegex, "$1\n        \$this->DR_Unregister();$2", $content, 1);
        } else {
            echo "Failed to match Destroy in $mod despite strpos\n";
        }
    }
    
    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        echo "Updated $mod\n";
    } else {
        echo "No changes for $mod\n";
    }
}
