<?php
$id = IPS_GetInstanceListByModuleID('{1CAD28D8-76C6-441A-B898-82EB3CF0DC5A}')[0];
$start = microtime(true);
WLED_RequestAction($id, 'Power', false);
echo "Time: " . ((microtime(true) - $start) * 1000) . "ms\n";
