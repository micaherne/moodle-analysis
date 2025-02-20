<?php

namespace MoodleAnalysis\Analyse;

use Generator;
use MoodleAnalysis\Codebase\MoodleClone;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FindingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Socket\TcpConnector;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Process\Process;

class ParallelAnalyserNew
{

    private FilesystemAdapter $blobCache;

    public function __construct(private MoodleClone $moodleClone, private string $blobCacheDir)
    {
        $this->blobCache = new FilesystemAdapter(namespace: 'blobs', directory: $this->blobCacheDir);
    }

    public function startJobProvider(string $branch): Process
    {
        $php = $this->get_self_bootstrap_php() . ' echo $analyser->jobProviderWorker("' . $branch . '");';

        $serverProc = new PhpProcess(
            script: $php,
            timeout: 0
        );
        $serverProc->start();
        $serverProc->waitUntil(fn($type, $buffer) => str_contains($buffer, 'tcp://'));
        return $serverProc;
    }

    public function startAnalyser(string $jobProcessAddress)
    {
        $php = $this->get_self_bootstrap_php() . ' echo $analyser->analyserWorker("' . $jobProcessAddress . '");';

        $serverProc = new PhpProcess(
            script: $php,
            timeout: 0
        );
        $serverProc->start();
        $serverProc->waitUntil(fn($type, $buffer) => str_contains($buffer, 'tcp://'));
        return $serverProc;
    }



    public function jobProviderWorker(string $branch): string
    {
        $socket = new SocketServer('127.0.0.1:0');
        $jobProvider = $this->getJobProvider($branch);
        $socket->on('connection', function (ConnectionInterface $connection) use ($socket, $jobProvider) {
            $connection->on('data', function ($chunk) use ($socket, $connection, $jobProvider) {
                $command = trim($chunk);
                if ($command === '@EXIT') {
                    $connection->close();
                    $socket->close();
                } elseif ($command === '@JOB') {
                    if ($jobProvider->valid()) {
                        $connection->write(
                            json_encode(['filePath' => $jobProvider->key(), 'blobId' => $jobProvider->current()]
                            ) . PHP_EOL
                        );
                        $jobProvider->next();
                    } else {
                        $connection->write("@END");
                        $connection->end();
                        $socket->close();
                    }
                } else {
                    $connection->write("@ERROR: Invalid command: {$command}\n");
                }
            });
        });
        return $socket->getAddress();
    }

    public function analyserWorker(string $jobProcessAddress)
    {
        // Setup the parser etc.
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $classFinder = new FindingVisitor(fn(Node $node) => $node instanceof ClassLike);
        $traverser = new NodeTraverser(new NameResolver(), $classFinder);

        $socket = new SocketServer('127.0.0.1:0');
        $connector = new TcpConnector();
        $connector->connect($jobProcessAddress)
            ->then(function (ConnectionInterface $connection) use ($socket, $connector, $parser, $traverser, $classFinder) {
                $connection->on('data', function($data) use ($socket, $connection, $parser, $traverser, $classFinder) {
                    $command = trim($data);
                    if ($command === '@END' || str_starts_with($command, '@ERROR')) {
                        $socket->close();
                        return;
                    }
                    $json = json_decode($data);
                    if (!$json) {
                        $socket->close();
                        return;
                    }
                    $filePath = $json->filePath;
                    $blobId = $json->blobId;

                    $classlikes = [];
                    $cacheItem = $this->blobCache->getItem($blobId);
                    if ($cacheItem->isHit()) {
                        $classlikes = $cacheItem->get();
                    } else {
                        $contentProc = new Process(['git', '-C', $this->moodleClone->getPath(), 'cat-file', 'blob', $json->blobId]);
                        $contentProc->mustRun();
                        $content = $contentProc->getOutput();
                        $nodes = $parser->parse($content);
                        $traverser->traverse($nodes);

                        /** @var ClassLike $foundNode */

                        foreach ($classFinder->getFoundNodes() as $foundNode) {
                            if ($foundNode->namespacedName === null) {
                                continue;
                            }
                            $classlikes[] = $foundNode->namespacedName->name;
                        }
                        $this->blobCache->save($cacheItem->set($classlikes));
                    }

                    $out = fopen('log.txt', 'a');
                    fputcsv($out, [$filePath, $blobId, ...$classlikes]);
                    fclose($out);

                    $connection->write("@JOB\n");
                });

                $connection->write("@JOB\n");
            });
        return $socket->getAddress();
    }

    public function getJobProvider(string $branch): Generator
    {
        $fileListProc = new Process(['git', '-C', $this->moodleClone->getPath(), 'ls-tree', '-r', $branch]);
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
     * @return string
     */
    private function get_self_bootstrap_php(): string
    {
        $autoloaderPath = realpath(dirname(__DIR__, 2) . '/vendor/autoload.php');
        return '<?php require_once "' . $autoloaderPath . '"; $clone = new ' . MoodleClone::class . '("' . $this->moodleClone->getPath(
            ) . '");
$analyser = new ' . __CLASS__ . '($clone, "' . var_export($this->blobCacheDir, true) . '");';
    }


}
