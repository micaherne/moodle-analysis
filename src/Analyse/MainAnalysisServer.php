<?php

namespace MoodleAnalysis\Analyse;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FindingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Psr\Cache\CacheItemPoolInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Symfony\Component\Process\Process;
use Throwable;

class MainAnalysisServer implements AnalysisServer
{

    public function __construct(
        private string $moodleCloneRoot,
        private ?CacheItemPoolInterface $cache = null
    ) {
    }

    public function run(): void
    {
        $server = new SocketServer('127.0.0.1:0');

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.2'));
        $classNodeFinder = new FindingVisitor(fn(Node $node): bool => $node instanceof ClassLike);
        $aliasFinder = new FindingVisitor('MoodleAnalysis\Analyse\ClassAliasUtil::isClassAliasCall');
        $traverser = new NodeTraverser(new NameResolver(), $classNodeFinder, $aliasFinder);

        $server->on('connection', function (ConnectionInterface $connection) use ($parser, $traverser, $classNodeFinder, $aliasFinder) {
            $connection->on('data', function ($data) use ($connection, $parser, $traverser, $classNodeFinder, $aliasFinder) {

                $payload = json_decode(trim($data), true);
                if ($payload === null) {
                    $connection->write("Invalid JSON\n");
                    return;
                }

                $blobName = $payload['blobName'];

                if ($this->cache !== null && $this->cache->hasItem($blobName)) {
                    $item = $this->cache->getItem($blobName);
                    $connection->write($item->get());
                    return;
                }

                $contentProc = new Process(['git', '-C', $this->moodleCloneRoot, 'cat-file', 'blob', $blobName]);

                $contentProc->mustRun();
                $content = $contentProc->getOutput();

                $result = ['classlikes' => [], 'class_aliases' => []];

                if (!preg_match('/(class|interface|trait|enum|class_alias)/', $content)) {
                    $connection->write(json_encode($result));
                    if ($this->cache !== null) {
                        $this->cache->save($this->cache->getItem($blobName)->set(json_encode($result)));
                    }
                    return;
                }

                $nodes = $parser->parse($content);
                $traverser->traverse($nodes);
                $classes = $classNodeFinder->getFoundNodes();

                /** @var ClassLike $class */
                foreach ($classes as $class) {
                    if ($class->namespacedName === null) {
                        continue;
                    }
                    $result['classlikes'][] = $class->namespacedName->name;
                }

                foreach(ClassAliasUtil::classAliasMap($aliasFinder->getFoundNodes()) as $original => $alias) {
                    // echo "Found alias: $original => $alias\n";
                    $result['class_aliases'][] = ['original' => $original, 'alias' => $alias];
                }

                if ($this->cache !== null) {
                    $this->cache->save($this->cache->getItem($blobName)->set(json_encode($result)));
                }

                $connection->write(json_encode($result));
            });
        });

        echo $server->getAddress();
    }

}