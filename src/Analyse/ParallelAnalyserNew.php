<?php

namespace MoodleAnalysis\Analyse;

use Composer\Autoload\ClassLoader;
use Generator;
use MoodleAnalysis\Analyse\Analyser\SymbolsAnalyser;
use MoodleAnalysis\Codebase\MoodleClone;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter\Standard;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Socket\TcpConnector;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Process\Process;

final readonly class ParallelAnalyserNew
{

    private FilesystemAdapter $blobCache;

    public function __construct(private MoodleClone $moodleClone, private string $cacheDir, private ?SymbolsAnalyser $analyser = null)
    {
        $this->blobCache = new FilesystemAdapter(namespace: 'blobs', directory: $this->cacheDir);
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
        $php = $this->get_self_bootstrap_php() . ' echo $analyser->analyserWorker(' . var_export($jobProcessAddress, true) . ');';

        $serverProc = new PhpProcess(
            script: $php,
            timeout: 0
        );
        $serverProc->start(function ($type, $data) {
            if ($type === 'err') {
                echo "$data\n";
            }
        });
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

        $socket = new SocketServer('127.0.0.1:0');
        $connector = new TcpConnector();
        $connector->connect($jobProcessAddress)
            ->then(function (ConnectionInterface $connection) use ($socket, $connector) {
                $connection->on('data', function ($data) use ($socket, $connection) {
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

                    $result = null;
                    $cacheItem = $this->blobCache->getItem($blobId);
                    if ($cacheItem->isHit()) {
                        $result = $cacheItem->get();
                    } else {
                        $contentProc = new Process(['git', '-C', $this->moodleClone->getPath(), 'cat-file', 'blob', $json->blobId]);
                        $contentProc->mustRun();
                        $content = $contentProc->getOutput();

                        $result = $this->analyser->analyse($content);

                        $this->blobCache->save($cacheItem->set($result));
                    }

                    $out = fopen('log.txt', 'a');
                    fputcsv($out, [$filePath, $blobId, ...$result->classLikes, ...$result->functionLikes]);
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
    public function get_self_bootstrap_php(): string
    {

        $classloader = new ReflectionClass(ClassLoader::class);
        $vendorDir = dirname($classloader->getFileName(), 2);

        $autoloaderPath = realpath($vendorDir . '/autoload.php');
        $b = new BuilderFactory();
        $stmts = [];
        $stmts[] = new Expression($b->funcCall('require_once', [$autoloaderPath]));
        $stmts[] = new Expression(new Assign($b->var('c'), $b->new(MoodleClone::class, [realpath($this->moodleClone->getPath())])));
        $stmts[] = new Expression(new Assign($b->var('x'), $b->new(SymbolsAnalyser::class)));
        $stmts[] = new Expression(new Assign($b->var('analyser'), $b->new(ParallelAnalyserNew::class, [$b->var('c'), $this->cacheDir, $b->var('x')])));

        $prettyPrinter = new Standard();
        return $prettyPrinter->prettyPrintFile($stmts);

    }


}
