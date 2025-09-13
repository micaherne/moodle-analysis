<?php

namespace MoodleAnalysis\Console\Command;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use MoodleAnalysis\Codebase\MoodleClone;
use MoodleAnalysis\Codebase\MoodleCloneProvider;
use MoodleAnalysis\Console\Command\Worker\CheckClassloaderWorker;
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
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'check:classloader',
    description: 'Check classloader will load all classes and aliased classes',
)]
class CheckClassloader extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('tag', InputArgument::REQUIRED, 'The tag being analysed')
            ->addArgument('moodle-repo', InputArgument::OPTIONAL, 'The path to the Moodle repository')
            ->addOption('from', 'f', InputOption::VALUE_NONE, 'Check all tags from the given one?')
            ->addOption('worker', 'w', InputOption::VALUE_NONE, 'Run as worker');
    }


    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new ConsoleLogger($output);
        $isWorker = (bool) $input->getOption('worker');
        if ($isWorker) {
            return $this->executeWorker($input, $logger);
        }

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

        $tag = $input->getArgument('tag');
        $from = (bool) $input->getOption('from');

        $tags = [];
        if ($from) {
            $allTags = $clone->getTags();
            $earliestTagOfInterest = $tag;

            $tags = array_filter($allTags, fn($tag): bool => Comparator::greaterThanOrEqualTo($tag, $earliestTagOfInterest)
                && VersionParser::parseStability($tag) === 'stable');
        } else {
            $tags[] = $tag;
        }

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
            $inputArguments = $input->getArguments();

            $commandParts = [
                ...ProcessUtil::getPhpCommand(),
                $_SERVER['argv'][0],
                $inputArguments['command'],
                '--worker',
                $tag,
                $clone->getPath(),
            ];

            $output->writeln("Checking $tag");
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

        if (!is_dir($repoLocation)) {
            throw new \InvalidArgumentException('The Moodle repository does not exist');
        }

        $worker = new CheckClassloaderWorker();
        return $worker->run($repoLocation, $logger, $tag);
    }


}
