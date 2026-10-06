<?php

declare(strict_types=1);

namespace Accord\Symfony\DependencyInjection;

use Accord\Symfony\Accord;
use Accord\Symfony\DefinitionProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Hands the single `accord.definition` service to {@see Accord}; zero or several is an error. */
final class DefinitionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Accord::class)) {
            return;
        }
        $ids = array_keys($container->findTaggedServiceIds(DefinitionProvider::TAG));
        if (\count($ids) !== 1) {
            throw new \LogicException(\sprintf('accord: exactly one service must implement %s (tag "%s"), found %d%s', DefinitionProvider::class, DefinitionProvider::TAG, \count($ids), $ids === [] ? '' : ': ' . implode(', ', $ids)));
        }
        $container->getDefinition(Accord::class)->setArgument('$provider', new Reference($ids[0]));
    }
}
