<?php

namespace App\Command;

use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Service\TaskProcessorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'agi:tasks:process',
    description: 'Process tasks that are in ready to process status.',
)]
class ProcessTasksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskProcessorService $taskProcessorService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskRepo = $this->entityManager->getRepository(Task::class);
        /** @var Task[] $tasks */
        $tasks = $taskRepo->findBy(['status' => TaskStatus::READY_TO_PROCESS]);

        foreach ($tasks as $task) {
            $output->writeln($task->project->name . ': processing ' . $task->name . ' ' . $task->id);
            $this->taskProcessorService->processTask($task);
        }

        return Command::SUCCESS;
    }
}
