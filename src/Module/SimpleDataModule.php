<?php


namespace Efrogg\ContentRenderer\Module;


use Efrogg\ContentRenderer\Node;

class SimpleDataModule implements ModuleInterface, DataModuleInterface
{
    public function canResolve($solvable, string $resolverName): bool
    {
        return true;
    }


    public function getPriority(): int
    {
        return 0;
    }


    /**
     * @param  Node  $node
     * @return array
     */
    public function getNodeData(Node $node): array
    {
        return $node->getData();
    }


}