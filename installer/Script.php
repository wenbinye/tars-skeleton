<?php

namespace wenbinye\tars\installer;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;

class Script
{
    const PROTOCOL_HTTP = 'http';
    const PROTOCOL_TARS = 'tars';
    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var RootPackageInterface
     */
    private $rootPackage;

    /**
     * @var JsonFile
     */
    private $composerJson;

    /**
     * @var array
     */
    private $composerDefinition;

    /**
     * @var \Composer\Package\Link[]
     */
    private $composerRequires;

    /**
     * @var \Composer\Package\Link[]
     */
    private $composerDevRequires;

    /**
     * @var array
     */
    private $stabilityFlags;

    /**
     * @var string
     */
    private $packageName;

    /**
     * @var string
     */
    private $protocol;

    /**
     * @var string
     */
    private $namespace;

    /**
     * @var string
     */
    private $appName;

    /**
     * + * @var string
     */
    private $serverName;

    private static $REQUIRES = [
        self::PROTOCOL_HTTP => [
            'slim/slim' => '^4.0'
        ],
        self::PROTOCOL_TARS => [
        ]
    ];

    private static $INSTALLER_DEPS = [
        "composer/composer"
    ];

    private static $PLACEHOLDER_FILES = [
        "config.conf.example",
        "src/controllers/IndexController.php"
    ];

    public function __construct(IOInterface $io, Composer $composer, ?string $projectRoot = null)
    {
        $this->io = $io;
        $this->composer = $composer;

        $this->fileSystem = new Filesystem();

        // Get composer.json location
        $composerFile = Factory::getComposerFile();

        // Calculate project root from composer.json, if necessary
        $this->projectRoot = $projectRoot ?: realpath(dirname($composerFile));
        $this->projectRoot = rtrim($this->projectRoot, '/\\') . '/';

        // Parse the composer.json
        $this->parseComposerDefinition($composer, $composerFile);
    }

    /**
     * Parses the composer file and populates internal data
     */
    private function parseComposerDefinition(Composer $composer, string $composerFile): void
    {
        $this->composerJson = new JsonFile($composerFile);
        $this->composerDefinition = $this->composerJson->read();

        // Get root package or root alias package
        $this->rootPackage = $composer->getPackage();
        $this->composerRequires = $this->rootPackage->getRequires();
        $this->composerDevRequires = $this->rootPackage->getDevRequires();
        $this->stabilityFlags = $this->rootPackage->getStabilityFlags();
    }

    public static function install(Event $event): void
    {
        $installer = new self($event->getIO(), $event->getComposer());

        $installer->io->write('<info>Setting up optional packages</info>');
        $installer->packageName = $installer->askPackageName();
        $installer->protocol = $installer->askProtocol();
        $installer->namespace = $installer->askNamespace();
        $installer->appName = $installer->askAppName();
        $installer->serverName = $installer->askServerName();

        $installer->replacePlaceHolder();
        $installer->setupForProtocol();
        $installer->fixComposerDefinition();
        $installer->addPackages();
        $installer->createConfig();
        $installer->updateRootPackage();
        $installer->finalizePackage();
    }

    /**
     * Update the root package based on current state.
     */
    private function updateRootPackage(): void
    {
        $this->rootPackage->setRequires($this->composerRequires);
        $this->rootPackage->setDevRequires($this->composerDevRequires);
        $this->rootPackage->setStabilityFlags($this->stabilityFlags);
        $this->rootPackage->setAutoload($this->composerDefinition['autoload']);
        $this->rootPackage->setDevAutoload($this->composerDefinition['autoload-dev']);
        $this->rootPackage->setExtra($this->composerDefinition['extra'] ?? []);
    }

    private function fixComposerDefinition(): void
    {
        $this->io->write('<info>Removing installer development dependencies</info>');
        foreach (self::$INSTALLER_DEPS as $devDependency) {
            unset($this->composerDefinition['require-dev'][$devDependency],
                $this->composerDevRequires[$devDependency],
                $this->stabilityFlags[$devDependency]);
        }

        $this->io->write('<info>Remove installer</info>');
        $this->composerDefinition['name'] = $this->packageName;
        $this->composerDefinition['autoload']['psr-4'][$this->namespace . '\\'] = 'src/';
        $this->composerDefinition['autoload-dev']['psr-4'][$this->namespace . '\\'] = 'tests/';

        // Remove installer script autoloading rules
        unset($this->composerDefinition['autoload']['psr-4']['wenbinye\\tars\\installer\\']);

        // Remove installer scripts
        unset($this->composerDefinition['scripts']['pre-update-cmd']);
        unset($this->composerDefinition['scripts']['pre-install-cmd']);
    }

    private function addPackages(): void
    {
        foreach (self::$REQUIRES[$this->protocol] as $packageName => $packageVersion) {
            $this->io->write(sprintf(
                '  - Adding package <info>%s</info> (<comment>%s</comment>)',
                $packageName,
                $packageVersion
            ));

            // Get the version constraint
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($packageVersion);

            // Create package link
            $link = new Link('__root__', $packageName, $constraint, 'requires', $packageVersion);

            unset($this->composerDefinition['require-dev'][$packageName],
                $this->composerDevRequires[$packageName]);

            $this->composerDefinition['require'][$packageName] = $packageVersion;
            $this->composerRequires[$packageName] = $link;

            // Set package stability if needed
            switch (VersionParser::parseStability($packageVersion)) {
                case 'dev':
                    $this->stabilityFlags[$packageName] = BasePackage::STABILITY_DEV;
                    break;
                case 'alpha':
                    $this->stabilityFlags[$packageName] = BasePackage::STABILITY_ALPHA;
                    break;
                case 'beta':
                    $this->stabilityFlags[$packageName] = BasePackage::STABILITY_BETA;
                    break;
                case 'RC':
                    $this->stabilityFlags[$packageName] = BasePackage::STABILITY_RC;
                    break;
            }
        }
    }

    private function finalizePackage(): void
    {
        // Update composer definition
        $this->composerJson->write($this->composerDefinition);
        $this->fileSystem->remove($this->projectRoot . '/installer');
    }

    private function askPackageName(): string
    {
        $defaultNs = get_current_user() . '/' . basename(getcwd());
        $query = ["<question>Package name (<vendor>/<name>)</question><comment>($defaultNs)</comment>: "];

        while (true) {
            $answer = $this->io->ask(implode($query), $defaultNs);
            if ($this->isValidPackage($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid package name</error>');
        }
    }

    private function askProtocol(): string
    {
        $query = [
            "<question>Communicate protocol</question>: \n",
            "[<comment>1</comment>] Http\n",
            "[<comment>2</comment>] Tars\n",
            'Make your selection <comment>(2)</comment>: ',
        ];

        while (true) {
            $answer = $this->io->ask(implode($query), '2');

            switch ($answer) {
                case '1':
                    return self::PROTOCOL_HTTP;
                case '2':
                    return self::PROTOCOL_TARS;
                default:
                    $this->io->write('<error>Invalid answer</error>');
            }
        }
    }

    private function askNamespace(): string
    {
        $defaultNs = basename(getcwd());
        $query = ["<question>Namespace</question><comment>($defaultNs)</comment>: "];

        while (true) {
            $answer = $this->io->ask(implode($query), $defaultNs);
            if ($this->isValidNamespace($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid namespace</error>');
        }
    }

    private function askAppName(): string
    {
        while (true) {
            $answer = $this->io->ask("<question>Tars application name</question>: ");
            if ($this->isValidName($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid app name, it should be matching \w+</error>');
        }
    }

    private function askServerName(): string
    {
        while (true) {
            $answer = $this->io->ask("<question>Tars server name</question>: ");
            if ($this->isValidName($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid server name, it should be matching \w+</error>');
        }
    }

    private function isValidNamespace(string $answer): bool
    {
        return (bool)preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*[a-zA-Z0-9_\x7f-\xff]$/', $answer);
    }

    private function isValidName(string $answer): bool
    {
        return (bool)preg_match('/^\w+$/', $answer);
    }

    private function isValidPackage(string $packageName): bool
    {
        return (bool)preg_match("#[a-z0-9_.-]+/[a-z0-9_.-]+#", $packageName);
    }

    private function replacePlaceHolder(): void
    {
        foreach (self::$PLACEHOLDER_FILES as $file) {
            $content = file_get_contents($file);
            $replace = strtr($content, [
                '{namespace}' => $this->namespace,
                '{AppName}' => $this->appName,
                '{ServerName}' => $this->serverName,
                '{protocol}' => $this->protocol === self::PROTOCOL_TARS ? 'tars' : 'not_tars'
            ]);
            file_put_contents($file, $replace);
        }
    }

    private function setupForProtocol(): void
    {
        $this->composerDefinition['extra']['tars']['serverName'] = $this->serverName;
        $this->fileSystem->rename("src/config.{$this->protocol}.php", "src/config.php");
        $this->fileSystem->remove(glob("src/config.*.php"));
        switch ($this->protocol) {
            case self::PROTOCOL_HTTP:
                $this->setupForHttpProtocol();
                break;
            case self::PROTOCOL_TARS:
                $this->setupForTarsProtocol();
                break;
            default:
                throw new \InvalidArgumentException("Unknown protocol " . $this->protocol);
        }
    }

    private function setupForHttpProtocol(): void
    {
        $this->composerDefinition['extra']['tars']['generator']['client'] = [
            [
                'servants' => [
                    'Hello' => 'FooApp.FooServer.HelloObj'
                ]
            ]
        ];
        $this->composerDefinition['extra']['kuiper']['configuration'] = [
            "kuiper\\cache\\CacheConfiguration",
            "kuiper\\web\\WebConfiguration",
            "kuiper\\web\\http\\DiactorosHttpMessageFactoryConfiguration"
        ];
        $this->fileSystem->remove("tars/servant");
    }

    private function setupForTarsProtocol(): void
    {
        $this->fileSystem->remove("src/controllers");
        $this->fileSystem->remove("tars/client");
    }

    private function createConfig(): void
    {
        $filename = 'config.conf.example';
        if (file_exists($filename)) {
            $content = file_get_contents($filename);
            $replace = strtr($content, [
                '{tarsnode}' => '127.0.0.1',
            ]);
            file_put_contents(str_replace('.example', '', $filename), $replace);
        } else {
            $this->io->write("<error>Config file $filename not found</error>");
        }
    }
}