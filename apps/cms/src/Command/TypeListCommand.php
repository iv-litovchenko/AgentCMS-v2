<?php

namespace App\Command;

use App\Service\TypeSchemaRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:types:list', description: 'Список типов (слои merge + extends)')]
class TypeListCommand extends Command
{
    private TypeSchemaRegistry $typeSchemaRegistry;

    public function __construct(TypeSchemaRegistry $typeSchemaRegistry)
    {
        parent::__construct();
        $this->typeSchemaRegistry = $typeSchemaRegistry;
    }

    protected function configure(): void
    {
        $this->addOption('workspace', 'w', InputOption::VALUE_OPTIONAL, 'Slug workspace для верхнего слоя');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspace = $input->getOption('workspace');
        $workspaceSlug = '';
        if (is_string($workspace)) {
            $workspaceSlug = $workspace;
        }

        $ids = $this->typeSchemaRegistry->listResolvedTypeIds($workspaceSlug);
        foreach ($ids as $typeId) {
            $def = $this->typeSchemaRegistry->resolveType($typeId, $workspaceSlug);
            $name = '';
            if (is_array($def)) {
                if (isset($def['name']) && is_string($def['name'])) {
                    $name = $def['name'];
                } elseif (isset($def['label']) && is_string($def['label'])) {
                    $name = $def['label'];
                }
            }
            $line = $typeId;
            if ($name !== '') {
                $line .= ' — ' . $name;
            }
            $output->writeln($line);
        }

        $output->writeln('');
        $output->writeln((string) count($ids) . ' type(s)');

        return Command::SUCCESS;
    }
}
