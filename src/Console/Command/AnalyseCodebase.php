<?php

namespace MoodleAnalysis\Console\Command;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use MoodleAnalysis\Analyse\ParallelAnalyser;
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
    public function __construct(private readonly MoodleCloneProvider $cloner, ?string $name = null)
    {
        parent::__construct($name);
    }


    protected function configure()
    {
        $this->addArgument('earliest-tag', InputArgument::REQUIRED, 'The earliest tag to start from');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $earliestTag = $input->getArgument('earliest-tag');

        $libraryRoot = __DIR__ . '/../../../';

        $output->writeln("Cloning Moodle");
        $clone = $this->cloner->cloneMoodle(bare: true);

        $moodleRoot = $clone->getPath();

        $cache = new FilesystemAdapter('blobs', 0, $libraryRoot . '/.analysis.cache/main');

        $counter = new CpuCoreCounter();
        $cpus = $counter->getAvailableForParallelisation()->availableCpus;

        foreach ($clone->getTags(from: $earliestTag, stableOnly: true) as $tag) {
            $output->writeln("Processing {$tag}");
            $analyser = new ParallelAnalyser($moodleRoot, $tag, $cpus, $cache);

            $processingPromise = $analyser->startProcessing();

            $processingPromise->then(function ($data) use ($tag, $libraryRoot, $output) {
                $outputFile = $tag . '.json';
                $output->writeln("Writing {$outputFile}");
                file_put_contents($libraryRoot . '/resources/main-analysis/' . $outputFile, json_encode($data, JSON_UNESCAPED_SLASHES));
            });

            await($processingPromise);
        }

        $clone->delete();

        return Command::SUCCESS;
    }


}