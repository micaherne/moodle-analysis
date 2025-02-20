<?php

namespace MoodleAnalysis\Analyse;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Fiber;
use Generator;
use InvalidArgumentException;
use MoodleAnalysis\Codebase\MoodleClone;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Process\Process;

use Throwable;

use function React\Promise\all;

class ParallelAnalyser {

    private Connector $connector;

    /**
     * @var array<ServerProcess>
     */
    private array $servers;

    private array $results = [];
    private Deferred $deferred;

    /**
     * The job that is currently running on each server.
     *
     * @var array<?PromiseInterface>
     */
    private array $runningJobs = [];

    /**
     * @var Generator<string, string>
     */
    private Generator $jobProvider;

    private Fiber $jobAllocator;

    public function __construct(
        private string $moodleRoot,
        private readonly string $branch,
        private readonly int $serverCount,
        private readonly CacheItemPoolInterface $blobCache,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        // First is full clone, second is bare.
        if (!MoodleClone::isClone($this->moodleRoot)) {
            throw new InvalidArgumentException('Not a git repository');
        }
        $this->moodleRoot = realpath($this->moodleRoot) || throw new InvalidArgumentException("Not a valid path");
    }

    private function startServers(): void {
        $runScript = $this->getRunScript();

        for ($i = 0; $i < $this->serverCount; $i++) {
            $serverProc = new PhpProcess($runScript);
            $serverProc->start();
            $serverProc->waitUntil(fn($type, $buffer) => str_contains($buffer, 'tcp://'));
            $serverAddress = trim($serverProc->getOutput());
            $this->servers[] = new ServerProcess($serverAddress, $serverProc);
            $this->runningJobs[] = null;
        }
    }

    public function getJobProvider(): Generator {
        $fileListProc = new Process(['git', '-C', $this->moodleRoot, 'ls-tree', '-r', $this->branch]);
        $fileListProc->mustRun();

        $lines = explode("\n", $fileListProc->getOutput());
        $lines = array_filter($lines, fn($line): bool => !empty($line));
        $lines = array_filter($lines, fn($line): bool => str_ends_with($line, '.php'));
        $lines = array_filter($lines, fn($line): bool => !str_contains($line, 'lib/aws-sdk/src/data'));

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) !== 2) {
                continue;
            }
            $path = $parts[1];
            [, $type, $name] = explode(' ', $parts[0]);
            if ($type !== 'blob') {
                continue;
            }
            yield $path => $name;
        }
    }

    /**
     * @return Generator<string, array>
     * @throws Throwable
     */
    public function getItems(): Generator {
        $this->startServers();
        $this->jobProvider = $this->getJobProvider();

        foreach ($this->jobProvider as $path => $blobName) {

        }

        $this->jobAllocator = new Fiber(function () {
            foreach ($this->jobProvider as $path => $name) {

                // Check cache first.
                if ($this->blobCache->hasItem($name)) {
                    $this->logger->debug("Cache hit for $path");
                    $this->results[$path] = json_decode($this->blobCache->getItem($name)->get(), true);
                    continue;
                }

                $vacantServerNumber = null;
                foreach ($this->runningJobs as $serverNumber => $promise) {
                    if ($promise === null) {
                        $vacantServerNumber = $serverNumber;
                        break;
                    }
                }

                if ($vacantServerNumber === null) {
                    $vacantServerNumber = $this->jobAllocator->suspend();
                }

                $this->queueJob($vacantServerNumber, $path, $name);
            }

            // Wait for all outstanding jobs to finish.
            all($this->runningJobs)->then(function () {
                $this->stopServers();
                $this->deferred->resolve($this->results);
            });

        });

        $this->connector = new Connector();

        $this->jobAllocator->start();
    }

    public function startProcessing(): PromiseInterface {
        $this->deferred = new Deferred();

        $this->startServers();

        $this->jobProvider = $this->getJobProvider();

        $this->jobAllocator = new Fiber(function () {
            foreach ($this->jobProvider as $path => $name) {

                // Check cache first.
                if ($this->blobCache->hasItem($name)) {
                    $this->logger->debug("Cache hit for $path");
                    $this->results[$path] = json_decode($this->blobCache->getItem($name)->get(), true);
                    continue;
                }

                $vacantServerNumber = null;
                foreach ($this->runningJobs as $serverNumber => $promise) {
                    if ($promise === null) {
                        $vacantServerNumber = $serverNumber;
                        break;
                    }
                }

                if ($vacantServerNumber === null) {
                    $vacantServerNumber = $this->jobAllocator->suspend();
                }

                $this->queueJob($vacantServerNumber, $path, $name);
            }

            // Wait for all outstanding jobs to finish.
            all($this->runningJobs)->then(function () {
                $this->stopServers();
                $this->deferred->resolve($this->results);
            });

        });

        $this->connector = new Connector();

        $this->jobAllocator->start();

        return $this->deferred->promise();

    }

    private function queueJob(int $serverNumber, string $path, string $blobName): PromiseInterface
    {
        $server = $this->servers[$serverNumber];

        $deferred = new Deferred();
        $this->runningJobs[$serverNumber] = $deferred->promise();

        $this->connector->connect($server->serviceAddress)->then(function (ConnectionInterface $connection) use ($path, $blobName, $deferred, $serverNumber) {

            $connection->on('data', function ($data) use ($path, $blobName, $serverNumber, $deferred, $connection) {
                $result = json_decode($data, true);

                $this->results[$path] = $result;

                $this->logger->debug("Processed by server {$serverNumber}: $path");
                $deferred->resolve($result);
                $this->blobCache->save($this->blobCache->getItem($blobName)->set(json_encode($result)));

                $connection->close();

                if ($this->jobAllocator->isSuspended()) {
                    $this->jobAllocator->resume($serverNumber);
                }

            });

            $connection->write(json_encode(['blobName' => $blobName]));
        });

        return $deferred->promise();

    }


    /**
     * @return void
     */
    function stopServers(): void
    {
        foreach ($this->servers as $server) {
            $server->process->stop();
        }
    }

    private function getRunScript(): string {
        $runScriptTemplate = <<<'PHP'
            <?php
            require_once {{autoloadPath}};            
            (new \MoodleAnalysis\Analyse\MainAnalysisServer({{moodleCloneDir}}))->run();
            PHP;

        $classloaderPath = (new ReflectionClass(ClassLoader::class))->getFileName();
        $autoloadPath = realpath(dirname($classloaderPath, 2) . '/autoload.php');

        $runScript = str_replace(
            [
                '{{autoloadPath}}',
                '{{moodleCloneDir}}'
            ],
            [
                var_export($autoloadPath, true),
                var_export($this->moodleRoot, true)
            ],
            $runScriptTemplate
        );

        return $runScript;
    }

}
