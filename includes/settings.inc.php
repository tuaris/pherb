<?php
/**
 * Pherb — Settings Loader
 *
 * Reads settings.ini from APPLICATION_CONFDIR and exposes $SETTINGS globally.
 */

use Enchilada\Config\IniConfig;

$SETTINGS = null;

$settingsFile = APPLICATION_CONFDIR . 'settings.ini';
if (file_exists($settingsFile)) {
    $SETTINGS = IniConfig::load($settingsFile);
}
