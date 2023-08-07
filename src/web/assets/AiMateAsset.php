<?php

namespace vaersaagod\aimate\web\assets;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

use GuzzleHttp\Client;

use Dotenv\Dotenv;

class AiMateAsset extends AssetBundle
{

    /** @var string */
    public $sourcePath = '@vaersaagod/aimate/web/assets/dist';

    /** @var array */
    public $depends = [
        CpAsset::class,
    ];

    /** @var array */
    public $js = [[
        'aimate.js',
        'type' => 'module',
    ]];

    /** @var array */
    public $css = [
        'aimate.css',
    ];

    /** @var array */
    private array $_envVars;

    /** @var string */
    private string $_devServerUrl;

    /** @var string */
    private const DEV_SERVER_DEFAULT_PORT = '5173';

    /** @var string */
    private const APP_ENTRY_POINT = 'aimate.js';

    /** @var bool|null */
    private ?bool $_isDevServerRunning = null;

    /**
     * @inheritDoc
     */
    public function init(): void
    {
        parent::init();

        if ($this->_isDevServerRunning()) {

            // Prepend the Vite dev server's URL to relative JS resources
            $this->js = array_map([$this, '_prependDevServerUrl'], $this->js);

            // Remove relative CSS resources as they are served from Vite's dev server
            $this->css = array_filter($this->css, function (array|string $filePath) {
                if (is_array($filePath)) {
                    $filePath = $filePath[0];
                }
                return UrlHelper::isFullUrl($filePath);
            });
        }
    }

    /**
     * @param $view
     * @return void
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $this->_registerTranslations($view);
        }
    }

    /**
     * Registers all of RedirectMate's static translations, for use in JS
     *
     * @param View $view
     * @return void
     */
    private function _registerTranslations(View $view): void
    {
        $translations = @include(App::parseEnv('@vaersaagod/aimate/translations/en/aimate.php'));
        if (!is_array($translations)) {
            Craft::error('Unable to register translations', __METHOD__);
            return;
        }
        $view->registerTranslations('aimate', array_keys($translations));
    }

    /**
     * Prepends the dev server's URL to a resource file path
     *
     * @param array|string $filePath
     * @return array|string
     */
    private function _prependDevServerUrl(array|string $filePath): array|string
    {
        if (is_array($filePath)) {
            $fileArray = $filePath;
            $filePath = $fileArray[0] ?? '';
        }
        if ($filePath && !UrlHelper::isFullUrl($filePath)) {
            $devServerUrl = $this->_getDevServerUrl();
            $filePath = $devServerUrl . '/' . ltrim($filePath, '/');
        }
        if (isset($fileArray)) {
            $fileArray[0] = $filePath;
            $filePath = $fileArray;
        }
        return $filePath;
    }

    /**
     * Checks if the dev server is running
     *
     * @return bool
     */
    private function _isDevServerRunning(): bool
    {
        if (!App::devMode()) {
            return false;
        }
        if ($this->_isDevServerRunning === null) {
            $entryPointUrl = $this->_getDevServerUrl() . '/' . static::APP_ENTRY_POINT;
            try {
                (new Client([
                    'http_errors' => true,
                ]))->get($entryPointUrl);
                $this->_isDevServerRunning = true;
            } catch (\Throwable) {
                $this->_isDevServerRunning = false;
            }
        }
        return $this->_isDevServerRunning;
    }

    /**
     * Returns the URL to the dev server
     *
     * @return string
     */
    private function _getDevServerUrl(): string
    {
        if (!isset($this->_devServerUrl)) {
            $envVars = $this->_getEnvVars();
            ['scheme' => $scheme, 'host' => $host] = parse_url(\Craft::$app->getRequest()->getAbsoluteUrl());
            $devServerHost = $envVars['VITE_DEVSERVER_HOST'] ?? $host;
            if ($devServerHost === '0.0.0.0') {
                $devServerHost = 'localhost';
            }
            $devServerPort = $envVars['VITE_DEVSERVER_PORT'] ?? static::DEV_SERVER_DEFAULT_PORT;
            $this->_devServerUrl = "$scheme://$devServerHost:$devServerPort/src";
        }
        return $this->_devServerUrl;
    }

    /**
     * Returns an array of env vars from a .env file in the plugin's root folder
     *
     * @return array
     */
    private function _getEnvVars(): array
    {
        if (!isset($this->_envVars)) {
            $envFile = FileHelper::normalizePath(App::parseEnv('@vaersaagod/aimate') . '/../.env');
            if (
                class_exists('Dotenv\Dotenv') &&
                file_exists($envFile) &&
                !is_dir($envFile) &&
                $envFileContents = (file_exists($envFile) ? @file_get_contents($envFile) : null)
            ) {
                $this->_envVars = Dotenv::parse($envFileContents);
            } else {
                $this->_envVars = [];
            }
        }
        return $this->_envVars;
    }

}
