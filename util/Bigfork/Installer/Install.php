<?php
namespace Bigfork\Installer;

use Composer\Script\Event;
use Spyc;

/**
 * Our magic installererererer.
 * Everything in here is geared towards setting up a SilverStripe project.
 */
class Install
{
    /**
     * The file that contains environment information
     */
    const ENVIRONMENT_FILE = '_ss_environment.php';
    /**
     * @var string
     */
    private static $basePath = '';

    /**
     * Get the document root for this project. Attempts getcwd(), falls back to
     * directory traversal.
     * @return string
     */
    public static function getBasepath()
    {
        if (! self::$basePath) {
            $candidate = getcwd() ?: dirname(dirname(dirname(dirname(__FILE__))));
            self::$basePath = rtrim($candidate, DIRECTORY_SEPARATOR);
        }

        return self::$basePath;
    }

    /**
     * Returns a text description of the current environment type. Assumes
     * 'live' if it can't determine an environment type.
     * @return string
     */
    protected static function getEnvironmentType()
    {
        if ($file = self::getEnvironmentFile()) {
            include_once $file;

            if (defined('GLOBAL_ENVIRONMENT_TYPE')) {
                return GLOBAL_ENVIRONMENT_TYPE;
            }
        }

        return 'live';
    }

    /**
     * Try to find a file that contains information about the environment. Scans
     * the current working directory and all parents looking for the file.
     * @return string|boolean
     */
    protected static function getEnvironmentFile()
    {
        $envFile = self::ENVIRONMENT_FILE;
        $directory = realpath('.');

        // Traverse directories "upwards" until we hit an unreadable directory
        // or the root of the drive
        do {
            // Add the trailing slash we need to concatenate properly
            $directory .= DIRECTORY_SEPARATOR;

            // If it's readable, go ahead
            if (is_readable($directory)) {
                // If the file exists, return its path
                if (file_exists($directory.$envFile)) {
                    return $directory.$envFile;
                }
            } else {
                // If we can't read the directory, give up
                break;
            }

            // Go up a level
            $directory = dirname($directory);

            // If these are the same, we've hit the root of the drive
        } while (dirname($directory) != $directory);

        return false;
    }

    /**
     * Called after every "composer install" command.
     * @param  Composer\Script\Event $event
     * @return void
     */
    public static function postInstall(Event $event)
    {
        // Check environment type
        if (self::getEnvironmentType() !== 'dev') {
            exit;
        }

        $io = $event->getIO();
        $basePath = self::getBasepath();

        // If the theme has already been renamed, assume this setup is complete
        if (file_exists($basePath.'/themes/default')) {
            // Only try to rename things if the user actually provides some info
            if ($theme = $io->ask('Please specify the theme name: ')) {
                $config = array(
                    'theme' => $theme,
                    'description' => $io->ask('Please specify the project description: '),
                    'sql-host' => $io->ask('Please specify the database host: '),
                    'sql-name' => $io->ask('Please specify the database name: '),
                );

                self::applyConfiguration($config);
                self::removeReadme();
            }
        }

        if ($io->ask('Would you like to set up a vhost? (y/n): ') == 'y') {
            $hostName = $io->ask('Please specify the host name (excluding \'.dev\'): ');
            self::setupVhost($hostName);
        }

        self::installNpm();
        exit;
    }

    /**
     * @param  Composer\Script\Event $event
     * @return void
     */
    public static function postUpdate(Event $event)
    {
        // Check environment type
        if (self::getEnvironmentType() !== 'dev') {
            exit;
        }

        self::installNpm();
        exit;
    }

    /**
     * Rename the 'default' theme directory, amend package name, description and
     * settings in package.json (if present). Also updates a few SilverStripe
     * configuration files.
     * @param  array $config
     * @return void
     */
    protected static function applyConfiguration(array $config)
    {
        $base = self::getBasepath();
        include $base.'/framework/thirdparty/spyc/spyc.php';

        // Rename theme directory
        $themeBase = $base.'/themes/';
        rename($themeBase.'default/', $themeBase.$config['theme'].'/');

        // Update package.json with provided information
        $packagePath = $base.'/package.json';
        if (file_exists($packagePath)) {
            self::updateNpmPackage($packagePath, $config);
        }

        // Update config.yml
        $configPath = $base.'/mysite/_config/config.yml';
        if (file_exists($configPath)) {
            self::updateYamlConfig($configPath, $config);
        }

        // Update info in logging configuration
        $loggingPath = $base.'/mysite/_config/logging.yml';
        if (file_exists($loggingPath)) {
            self::updateLoggingConfig($loggingPath, $config);
        }
    }

    /**
     * Update package.json with relevant information in provided config.
     * @param  string $filePath
     * @param  array  $config
     * @return void
     */
    protected static function updateNpmPackage($filePath, array $config)
    {
        $json = file_get_contents($filePath);
        $old = json_decode($json, true);
        $new = array(
            'name' => $config['theme'],
            'description' => $config['description'],
            'sql' => array(
                'name' => $config['sql-name'],
                'host' => $config['sql-host']
            )
        );

        $contents = array_merge($old, $new);
        $json = json_encode($contents);
        file_put_contents($filePath, $json);
    }

    /**
     * Update config.yml with relevant information in provided config.
     * @param  string $filePath
     * @param  array  $config
     * @return void
     */
    protected static function updateYamlConfig($filePath, array $config)
    {
        $yamlConfig = Spyc::YAMLLoad(file_get_contents($filePath));

        // Update YAML config
        $yamlConfig['SSViewer']['current_theme'] = $config['theme'];
        
        if (isset($config['sql-host']) || isset($config['sql-name'])) {
            $yamlConfig['Database']['host'] = $config['sql-host'];
            $yamlConfig['Database']['name'] = $config['sql-name'];
        }

        // Write our updated config file
        $yaml = Spyc::YAMLDump($yamlConfig);
        file_put_contents($filePath, $yaml);
    }

    /**
     * Update logging.yml with relevant information in provided config.
     * @param  string $filePath
     * @param  array  $config
     * @return void
     */
    protected static function updateLoggingConfig($filePath, array $config)
    {
        $yamlConfig = Spyc::YAMLLoad(file_get_contents($filePath));

        $desc = $config['description'] ?: 'App';
        $yamlConfig['Injector']['Monolog']['constructor'][0] = "'{$desc}'";

        $yaml = Spyc::YAMLDump($yamlConfig);
        file_put_contents($filePath, $yaml);
    }

    /**
     * Runs "npm install" if a package.json file is present in the project.
     * @return void
     */
    protected static function installNpm()
    {
        $basePath = self::getBasepath();
        if (file_exists($basePath.'/package.json')) {
            $current = __DIR__;
            chdir($basePath);
            echo shell_exec('npm install');
            chdir($current);
        }
    }

    /**
     * Setup a new vhost. Makes very opinionated assumptions about paths,
     * doesn't restart apache.
     * @param  string $hostName
     * @return void
     */
    protected static function setupVhost($hostName)
    {
        $folderPath = self::getBasepath();
        $dirName = rtrim(basename($folderPath), DIRECTORY_SEPARATOR);
        $fileName = '/private/etc/apache2/sites-enabled/'.$dirName.'.conf';

        $data = <<<XML
<VirtualHost *:80>
    DocumentRoot "$folderPath"
    ServerName $hostName.dev
    ServerAlias $hostName.t.proxylocal.com
</VirtualHost>
XML;

        file_put_contents($fileName, $data);
    }

    /**
     * Removes README.md from the project.
     * @return void
     */
    protected static function removeReadme()
    {
        $basePath = self::getBasepath();

        if (file_exists($basePath.'/README.md')) {
            unlink($basePath.'/README.md');
        }
    }
}