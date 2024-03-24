<?php

use MoodleAnalysis\Codebase\MoodleCloneProvider;
use MoodleAnalysis\Console\Command\CheckClassloader;
use MoodleAnalysis\Console\Command\ExtractMoodleVersions;
use MoodleAnalysis\Console\Command\GenerateClassloaderBootstrap;
use Symfony\Component\Console\Application;

require_once __DIR__ . '/../vendor/autoload.php';

$moodleCloneProvider = new MoodleCloneProvider();
$app = new Application('Extract', '0.1');
$app->addCommands([
    new ExtractMoodleVersions($moodleCloneProvider),
    new GenerateClassloaderBootstrap(),
    new CheckClassloader(),
]);
$app->setDefaultCommand('extract');

$app->run();