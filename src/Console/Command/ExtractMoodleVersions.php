<?php

namespace MoodleAnalysis\Console\Command;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use MoodleAnalysis\Codebase\MoodleCloneProvider;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'extract:moodle-versions',
    description: 'Extract version number map from Moodle')]
class ExtractMoodleVersions extends Command
{

    public function __construct(private readonly MoodleCloneProvider $cloner, ?string $name = null)
    {
        parent::__construct($name);
    }
    #[\Override] protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Extracting renamed classes from Moodle...');

        $clone = $this->cloner->cloneMoodle();

        $earliestTagOfInterest = 'v4.1.0';

        $fs = new Filesystem();

        $outputFile = __DIR__ . '/../../../resources/moodle-versions/versions.php';
        $fs->mkdir(dirname($outputFile));

        define('MOODLE_INTERNAL', 1);
        define('MATURITY_STABLE', 'stable');

        $tags = $clone->getTags();

        $filteredTags = array_filter($tags, fn($tag): bool => Comparator::greaterThanOrEqualTo($tag, $earliestTagOfInterest)
            && VersionParser::parseStability($tag) === 'stable');

        $versions = [];
        foreach ($filteredTags as $tag) {
            $output->writeln("Checking out $tag");
            $clone->clean();
            $clone->checkout($tag);

            $version = new stdClass();
            require $clone->getPath() . '/version.php';
            $versions[$tag] = $version;
        }
        $clone->delete();

        file_put_contents($outputFile, '<?php return ' . var_export($versions, true) . ';' . PHP_EOL);

        return Command::SUCCESS;
    }


}