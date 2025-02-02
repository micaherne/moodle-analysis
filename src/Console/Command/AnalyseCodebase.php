<?php

namespace MoodleAnalysis\Console\Command;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use MoodleAnalysis\Analyse\ParallelAnalyser;
use MoodleAnalysis\Analyse\Provider\MainAnalysisProvider;
use MoodleAnalysis\Codebase\MoodleClone;
use MoodleAnalysis\Codebase\MoodleCloneProvider;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function React\Async\await;

#[AsCommand(
    name: 'analyse:codebase',
    description: 'Run the main analysis on the Moodle codebase'
)]
class AnalyseCodebase extends Command
{
    public function __construct(private readonly MoodleCloneProvider $cloner, private readonly MainAnalysisProvider $analysisProvider, ?string $name = null)
    {
        parent::__construct($name);
    }


    protected function configure()
    {
        $this->addArgument('earliest-tag', InputArgument::REQUIRED, 'The earliest tag to start from')
            ->addArgument('moodle-repo-path', InputArgument::OPTIONAL, 'The path to an existing Moodle repository clone (full or bare)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $earliestTag = $input->getArgument('earliest-tag');
        $moodleRepoPath = $input->getArgument('moodle-repo-path');

        if ($moodleRepoPath === null) {
            $output->writeln("Cloning Moodle");
            $clone = $this->cloner->cloneMoodle(bare: true);

            $moodleRoot = $clone->getPath();
        } else {
            if (MoodleClone::isClone($moodleRepoPath)) {
                $moodleRoot = $moodleRepoPath;
                $clone = new MoodleClone($moodleRoot);
            } else {
                $output->writeln("The path provided is not a Moodle clone");
                return Command::FAILURE;
            }
        }

        $libraryRoot = __DIR__ . '/../../../';

        $cache = new FilesystemAdapter('blobs', 0, $libraryRoot . '/.analysis.cache/main');

        $counter = new CpuCoreCounter();
        $cpus = $counter->getAvailableForParallelisation()->availableCpus;

        foreach ($clone->getTags(from: $earliestTag, stableOnly: true) as $tag) {
            $output->writeln("Processing {$tag}");
            $analyser = new ParallelAnalyser($moodleRoot, $tag, $cpus, $cache);

            $processingPromise = $analyser->startProcessing();

            $processingPromise->then(function ($data) use ($tag, $output) {
                $outputFile = $this->analysisProvider->getAnalysisFileForTag($tag);
                $output->writeln("Writing {$outputFile}");
                file_put_contents($outputFile, json_encode($data, JSON_UNESCAPED_SLASHES));
            });

            await($processingPromise);
        }

        if ($moodleRepoPath === null) {
            $output->writeln("Deleting temporary clone");
            $clone->delete();
        }

        return Command::SUCCESS;
    }


}