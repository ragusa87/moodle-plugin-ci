<?php

/*
 * This file is part of the Moodle Plugin CI package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Copyright (c) 2018 Blackboard Inc. (http://www.blackboard.com)
 * License http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace MoodlePluginCI\Tests\Command;

use MoodlePluginCI\Command\InstallCommand;
use MoodlePluginCI\Command\PHPLintCommand;
use MoodlePluginCI\Installer\InstallerCollection;
use MoodlePluginCI\Installer\InstallOutput;
use MoodlePluginCI\Installer\PluginInstallerNoCopy;
use MoodlePluginCI\Tests\Fake\Installer\DummyInstall;
use MoodlePluginCI\Tests\Fake\Process\DummyExecute;
use MoodlePluginCI\Tests\MoodleTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;

class InstallCommandTest extends MoodleTestCase
{
    protected function executeCommand()
    {
        $command          = new InstallCommand($this->tempDir.'/.env');
        $command->install = new DummyInstall(new InstallOutput());

        $application = new Application();
        $application->add($command);

        $commandTester = new CommandTester($application->find('install'));
        $commandTester->execute([
            '--moodle'        => $this->tempDir.'/moodle',
            '--plugin'        => $this->pluginDir,
            '--data'          => $this->tempDir.'/moodledata',
            '--branch'        => 'MOODLE_29_STABLE',
            '--db-type'       => 'mysqli',
            '--extra-plugins' => $this->extrapluginDir,
        ]);

        return $commandTester;
    }

    public function testExecute()
    {
        $commandTester = $this->executeCommand();
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testMoodleDirEnv()
    {
        $previous = getenv('MOODLE_DIR');
        try {
            $fakeMoodleDir = $this->tempDir.'/moodle_fake';
            putenv('MOODLE_DIR='.$fakeMoodleDir);

            $command          = new InstallCommand($this->tempDir.'/.env');
            $command->install = new DummyInstall(new InstallOutput());

            $application = new Application();
            $application->add($command);

            $input   = new ArrayInput(
                [
                    '--no-clone' => true,
                    '--db-type'  => 'mysqli',
                ], $command->getDefinition()
            );
            $factory = $command->initializeInstallerFactory($input);
            $this->assertSame($fakeMoodleDir, $factory->moodle->directory);
        } finally {
            putenv('MOODLE_DIR='.$previous);
        }
    }

    public function testDbCreateSkipFlag()
    {
        $command          = new InstallCommand($this->tempDir.'/.env');
        $command->install = new DummyInstall(new InstallOutput());

        $application = new Application();
        $application->add($command);

        $input            = new ArrayInput(
            [
                '--db-create-skip' => true,
                '--db-type'        => 'mysqli',
                '--no-clone'       => true,
            ], $command->getDefinition()
        );
        $command->execute = new DummyExecute();
        $factory          = $command->initializeInstallerFactory($input);
        $collection       = new InstallerCollection(new InstallOutput());

        $factory->addInstallers($collection);
        $this->assertFalse($factory->createDb);
        $this->assertInstanceOf(PluginInstallerNoCopy::class, $collection->all()[1]);
    }

    public function testNoConfigRewriteFlag()
    {
        $command          = new InstallCommand($this->tempDir.'/.env');
        $command->install = new DummyInstall(new InstallOutput());

        $application = new Application();
        $application->add($command);

        $input            = new ArrayInput(
            [
                '--db-create-skip'    => true,
                '--db-type'           => 'mysqli',
                '--no-clone'          => true,
                '--no-config-rewrite' => true,
            ], $command->getDefinition()
        );
        $command->execute = new DummyExecute();
        $factory          = $command->initializeInstallerFactory($input);
        $this->assertTrue($factory->noConfigRewrite);
    }

    /**
     * @param string $value
     * @param array  $expected
     *
     * @dataProvider csvToArrayProvider
     */
    public function testCsvToArray($value, array $expected)
    {
        $command = new InstallCommand($this->tempDir.'/.env');
        $this->assertSame($expected, $command->csvToArray($value), "Converting this value: '$value'");
    }

    public function testInitializePluginConfigDumper()
    {
        putenv('PHPLINT_IGNORE_NAMES=foo.php,bar.php');
        putenv('PHPLINT_IGNORE_PATHS=bat,fiz/buz');

        $command          = new InstallCommand($this->tempDir.'/.env');
        $command->install = new DummyInstall(new InstallOutput());

        $lintCommand         = new PHPLintCommand();
        $lintCommand->plugin = $this->pluginDir;

        $application = new Application();
        $application->add($command);
        $application->add($lintCommand);

        $actual = $this->tempDir.'/config.yml';

        $input  = new ArrayInput(['--not-paths' => 'global/path', '--not-names' => 'global_name.php'], $command->getDefinition());
        $dumper = $command->initializePluginConfigDumper($input);
        $dumper->dump($actual);

        $expected = $this->dumpFile(
            'expected.yml',
            <<<'EOT'
filter:
    notPaths: [global/path]
    notNames: [global_name.php]
filter-phplint:
    notPaths: [bat, fiz/buz]
    notNames: [foo.php, bar.php]

EOT
        );

        $this->assertFileEquals($expected, $actual);
    }

    public function csvToArrayProvider()
    {
        return [
            [' , foo , bar ', ['foo', 'bar']],
            [' , ', []],
            [null, []],
        ];
    }
}
