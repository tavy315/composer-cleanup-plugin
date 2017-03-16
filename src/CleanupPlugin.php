<?php
namespace Tavy315\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Plugin\PluginInterface;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class CleanupPlugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer */
    protected $composer;

    /** @var IOInterface */
    protected $io;

    /** @var Config */
    protected $config;

    /** @var Filesystem */
    protected $filesystem;

    /** @var array */
    protected $rules;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->filesystem = new Filesystem();
        $this->rules = CleanupRules::getRules();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_PACKAGE_INSTALL => [ [ 'onPostPackageInstall', 0 ] ],
            ScriptEvents::POST_PACKAGE_UPDATE  => [ [ 'onPostPackageUpdate', 0 ] ],
            /*ScriptEvents::POST_INSTALL_CMD     => [ [ 'onPostInstallUpdateCmd', 0 ] ],*/
            /*ScriptEvents::POST_UPDATE_CMD      => [ [ 'onPostInstallUpdateCmd', 0 ] ],*/
        ];
    }

    /**
     * Function to run after a package has been installed
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        /** @var CompletePackage $package */
        $package = $event->getOperation()->getPackage();

        $this->cleanPackage($package);
    }

    /**
     * Function to run after a package has been updated
     */
    public function onPostPackageUpdate(PackageEvent $event)
    {
        /** @var CompletePackage $package */
        $package = $event->getOperation()->getTargetPackage();

        $this->cleanPackage($package);
    }

    /**
     * Function to run after a package has been updated
     *
     * @param CommandEvent $event
     */
    public function onPostInstallUpdateCmd(CommandEvent $event)
    {
        /** @var WritableRepositoryInterface $repository */
        $repository = $this->composer->getRepositoryManager()->getLocalRepository();

        /** @var CompletePackage $package */
        foreach ($repository->getPackages() as $package) {
            if ($package instanceof BasePackage) {
                $this->cleanPackage($package);
            }
        }
    }

    /**
     * Clean a package, based on its rules.
     *
     * @param BasePackage $package The package to clean
     *
     * @return bool True if cleaned
     */
    protected function cleanPackage(BasePackage $package)
    {
        // Only clean 'dist' packages
        if ($package->getInstallationSource() !== 'dist') {
            return false;
        }

        $vendorDir = $this->config->get('vendor-dir');
        $targetDir = $package->getTargetDir();
        $packageName = $package->getPrettyName();
        $packageDir = $targetDir ? $packageName . '/' . $targetDir : $packageName;

        if (!isset($this->rules[$packageName])) {
            return;
        }

        $rules = (array) $this->rules[$packageName];

        $dir = $this->filesystem->normalizePath(realpath($vendorDir . '/' . $packageDir));
        if (!is_dir($dir)) {
            return false;
        }

        foreach ($rules as $part) {
            // Split patterns for single globs (should be max 260 chars)
            $patterns = explode(' ', trim($part));

            foreach ($patterns as $pattern) {
                try {
                    foreach (glob($dir . '/' . $pattern) as $file) {
                        $this->io->write("Excluding {$file}");
                        $this->filesystem->remove($file);
                    }
                } catch (\Exception $e) {
                    $this->io->writeError("Could not parse $packageDir ($pattern): " . $e->getMessage());
                }
            }
        }

        return true;
    }
}
