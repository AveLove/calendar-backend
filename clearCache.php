<?php
require_once('init.php');

exec('rm -f ' . dirname(__FILE__) . '/Data/cache_*');
echo "Caches cleared";
