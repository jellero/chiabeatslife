<?php
declare(strict_types=1);

use Chiabeatslife\Http\ApplicationFactory;

$root = dirname(__DIR__);
defined('CHIABEATSLIFE_ROOT') || define('CHIABEATSLIFE_ROOT', $root);
defined('CHIABEATSLIFE_VIEW_PATH') || define('CHIABEATSLIFE_VIEW_PATH', $root . '/resources/views');

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Dipendenze mancanti: eseguire composer install prima di avviare il sito.');
}
require_once $autoload;

return ApplicationFactory::create($root);
