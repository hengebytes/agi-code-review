<?php

namespace App\Github\Command;

use App\Github\Message\Async\GithubPullRequestUpdate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'agi:github:create-pr-task',
    description: 'Create task by pull request URL'
)]
class CreateTaskByPullRequestCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Task creation by pull request');

        $prUrl = $io->ask('Pull request URL (ex. https://github.com/hengebytes/agi/pull/19)');
        if (!$prUrl) {
            $io->error('GitHub Pull request URL is required');

            return Command::FAILURE;
        }
        $prUrl = str_replace(['https://github.com/', '/pull'], '', trim($prUrl));
        [$owner, $repo, $prId] = explode('/', $prUrl);

        $this->bus->dispatch(new GithubPullRequestUpdate(
            $owner,
            $repo,
            $prId,
            'open',
        ));

        return Command::SUCCESS;
    }
}
