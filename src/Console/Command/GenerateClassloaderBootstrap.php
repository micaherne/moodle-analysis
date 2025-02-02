<?php

namespace MoodleAnalysis\Console\Command;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use MoodleAnalysis\Analyse\Provider\MainAnalysisProvider;
use MoodleAnalysis\Codebase\MoodleClone;
use MoodleAnalysis\Codebase\MoodleCloneProvider;
use MoodleAnalysis\Console\Command\Worker\GenerateClassloaderBootstrapWorker;
use MoodleAnalysis\Console\Process\ProcessUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'generate:classloader-bootstrap',
    description: 'Generate class loader bootstrap files',
)]
class GenerateClassloaderBootstrap extends Command
{
    #[\Override] protected function configure(): void
    {
        $this->addArgument('moodle-repo', InputArgument::OPTIONAL, 'The path to the Moodle repository')
            // The tag argument will be the specific tag to analyse when --worker is passed.
            ->addArgument('tag', InputArgument::OPTIONAL, 'The earliest tag to analyse')
            ->addOption('fix-classloader', 'f', InputOption::VALUE_NONE, 'Fix the classloader before generating?')
            ->addOption('worker', 'w', InputOption::VALUE_NONE, 'Run as worker');
    }


    #[\Override] protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new ConsoleLogger($output);
        $isWorker = (bool)$input->getOption('worker');
        if ($isWorker) {
            return $this->executeWorker($input, $logger);
        }

        // Ensure git and composer are installed in the path.
        /*if (exec('git --version') !== 0 || exec('composer --version') !== 0) {
            $output->writeln("Please ensure git and composer are installed and in the path.");
            return Command::FAILURE;
        }*/

        $repoLocation = $input->getArgument('moodle-repo');

        if ($repoLocation === null) {
            $output->writeln("Cloning Moodle...");
            $cloner = new MoodleCloneProvider();
            $clone = $cloner->cloneMoodle();
        } else {
            $realRepoLocation = realpath($repoLocation);
            if ($realRepoLocation === false) {
                throw new InvalidArgumentException("$realRepoLocation does not exist");
            }
            if (!MoodleClone::isStandardClone($realRepoLocation)) {
                throw new InvalidArgumentException("Existing repo must be a full checkout clone of Moodle");
            }
            $clone = new MoodleClone($realRepoLocation);
        }


        $earliestTagOfInterest = $input->getArgument('tag') ?? 'v4.2.0';

        $fs = new Filesystem();

        $classloaderBootstrapDirectory = __DIR__ . '/../../../resources/bootstrap-classloader';
        $fs->mkdir($classloaderBootstrapDirectory);

        $tags = $clone->getTags(from: $earliestTagOfInterest, stableOnly: true);

        // Check we have analysis for each tag, to avoid wasting time.
        $analysisProvider = new MainAnalysisProvider();
        foreach ($tags as $tag) {
            if (!$analysisProvider->analysisExistsForTag($tag)) {
                $output->writeln("Unable to find analysis for $tag. Please run analyse:codebase command.");
                return Command::FAILURE;
            }
        }

        $inputArguments = $input->getArguments();

        foreach ($tags as $tag) {
            $logger->info("Checking out $tag");
            $clone->clean();
            $clone->checkout($tag);

            $composerProcess = new Process(['composer', 'install', '--no-interaction'], $clone->getPath());
            $composerProcess->mustRun();

            // Spawn new processes to work with the checked out code.
            // This is necessary as we can only load core_component once per process.

            /** @var ProcessHelper $processHelper */
            $processHelper = $this->getHelper('process');

            // TODO: Pass verbosity flag through.

            $fixClassloader = $input->getOption('fix-classloader');

            $commandParts = [
                ...ProcessUtil::getPhpCommand(),
                $_SERVER['argv'][0],
                $inputArguments['command'],
                '--worker',
                $clone->getPath(),
                $tag
            ];

            if ($fixClassloader) {
                $commandParts[] = '-f';
            }

            $logger->debug("Running worker for $tag");
            $process = $processHelper->run($output, new Process($commandParts, timeout: null));
            $output->writeln($process->getErrorOutput());
            $output->writeln($process->getOutput());
        }

        if ($repoLocation === null) {
            $clone->delete();
        }

        return Command::SUCCESS;
    }

    private function executeWorker(InputInterface $input, LoggerInterface $logger): int
    {
        $repoLocation = $input->getArgument('moodle-repo');
        $tag = $input->getArgument('tag');
        $fixClassloader = $input->getOption('fix-classloader');

        if (!is_dir($repoLocation)) {
            throw new \InvalidArgumentException('The Moodle repository does not exist');
        }

        $worker = new GenerateClassloaderBootstrapWorker();
        return $worker->run($repoLocation, $logger, $tag, $fixClassloader);
    }


}