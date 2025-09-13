<?php

namespace MoodleAnalysis\Analyse;

use ArrayIterator;
use Iterator;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

class ClassAliasUtil {

    /**
     * Check if the given node is a call to class_alias with static parameters (i.e. strings or class constants)
     *
     * @param Node $node
     * @return bool
     */
    public static function isClassAliasCall(Node $node): bool {
        if (!($node instanceof Node\Expr\FuncCall)) {
            return false;
        }
        if (!($node->name instanceof Node\Name)) {
            return false;
        }
        if (!($node->name->toString() === 'class_alias')) {
            return false;
        }

        foreach ($node->getArgs() as $index => $arg) {
            // We don't care about the third argument to class_alias.
            if ($index > 1) {
                continue;
            }
            if (!($arg->value instanceof Node\Scalar\String_
                || (
                    $arg->value instanceof Node\Expr\ClassConstFetch
                    && $arg->value->name instanceof Node\Identifier
                    && $arg->value->name->toString() === 'class'
                )
            )
            ) {
                return false;
            }
        }

        return true;
    }

    public static function classAliasMap(array $nodes): Iterator {

        foreach ($nodes as $node) {
            if (self::isClassAliasCall($node)) {
                yield self::classNameToString($node->args[0]->value) => self::classNameToString($node->args[1]->value);
            }
        }
    }

    private static function classNameToString(ClassConstFetch|String_ $className): string {
        if ($className instanceof ClassConstFetch && $className->class instanceof Name) {
            return $className->class->name;
        }
        return $className->value;
    }
}
