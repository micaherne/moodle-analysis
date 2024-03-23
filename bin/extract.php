<?php

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use MoodleAnalysis\Codebase\MoodleClone;
use MoodleAnalysis\Codebase\MoodleCloneProvider;
use MoodleAnalysis\Console\Command\Extract;
use MoodleAnalysis\Console\Command\ExtractMoodleVersions;
use MoodleAnalysis\Console\Command\ExtractRenamedClasses;
use MoodleAnalysis\Console\Command\ExtractStaticAliases;
use MoodleAnalysis\Console\Command\ParallelTest;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

require_once __DIR__ . '/../vendor/autoload.php';

$moodleCloneProvider = new MoodleCloneProvider();
$app = new Application('Extract', '0.1');
$app->addCommands([
    new ExtractMoodleVersions($moodleCloneProvider)
]);
$app->setDefaultCommand('extract');

$app->run();