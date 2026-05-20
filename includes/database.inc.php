<?php
/**
 * Pherb — Database Helper
 *
 * Provides pherb_create_mariadb() for creating PDO connections.
 */

use Enchilada\Config\IniConfig;

/**
 * Create a MariaDB PDO connection from settings.ini
 *
 * @param IniConfig|null $settings
 * @return PDO
 */
function pherb_create_mariadb(?IniConfig $settings = null): PDO
{
    $host = getenv('PHERB_DB_HOST') ?: ($settings ? $settings->getString('mariadb', 'host', '127.0.0.1') : '127.0.0.1');
    $port = getenv('PHERB_DB_PORT') ?: ($settings ? $settings->getInt('mariadb', 'port', 3306) : 3306);
    $dbname = getenv('PHERB_DB_NAME') ?: ($settings ? $settings->getString('mariadb', 'database', 'pherb') : 'pherb');
    $user = getenv('PHERB_DB_USER') ?: ($settings ? $settings->getString('mariadb', 'username', 'pherb') : 'pherb');
    $pass = getenv('PHERB_DB_PASS') ?: ($settings ? $settings->getString('mariadb', 'password', '') : '');

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
