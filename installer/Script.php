<?php

namespace wenbinye\tars\installer;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;

class Script
{

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

        // Get optional packages configuration
        $this->config = require __DIR__ . '/config.php';

        // Source path for this file
        $this->installerSource = realpath(__DIR__) . '/';
    }
    /**
     * Parses the composer file and populates internal data
     */
    private function parseComposerDefinition(Composer $composer, string $composerFile) : void
    {
        $this->composerJson       = new JsonFile($composerFile);
        $this->composerDefinition = $this->composerJson->read();

        // Get root package or root alias package
        $this->rootPackage = $composer->getPackage();

        // Get required packages
        $this->composerRequires    = $this->rootPackage->getRequires();
        $this->composerDevRequires = $this->rootPackage->getDevRequires();

        // Get stability flags
        $this->stabilityFlags = $this->rootPackage->getStabilityFlags();
    }

    public static function install(Event $event): void
    {
        $installer = new self($event->getIO(), $event->getComposer());

        $installer->io->write('<info>Setting up optional packages</info>');

        $installer->removeDevDependencies();
        $installer->setInstallType($installer->requestInstallType());
        $installer->setupDefaultApp();
        $installer->promptForOptionalPackages();
        $installer->updateRootPackage();
        $installer->removeInstallerFromDefinition();
        $installer->finalizePackage();
    }

    private function removeDevDependencies()
    {
        
    }

    private function finalizePackage()
    {
        // Update composer definition
        $this->composerJson->write($this->composerDefinition);

        $this->cleanUp();
    }

    private function cleanUp() : void
    {
        $this->fileSystem->remove($this->projectRoot. '/installer');
    }
}